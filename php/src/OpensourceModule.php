<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Opensource\Domain\DonationRepository;
use Tds\Ext\Opensource\Domain\ProjectRepository;
use Tds\Ext\Opensource\Support\ClientIp;
use Tds\Ext\Opensource\Support\DonationAmount;
use Tds\Ext\Opensource\Support\DonationCheckout;
use Tds\Ext\Opensource\Support\DonationEvent;
use Tds\Ext\Opensource\Support\ProjectFields;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\ConnectedSiteCache;
use Tds\Frontend\Contract\ModuleHttp;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\SetupStatusSource;
use Tds\Frontend\Contract\SiteConnectionException;
use Tds\Frontend\Contract\SiteConnections;
use Tds\Frontend\Contract\SiteKeyProtected;
use Tds\Frontend\Contract\Stripe\CurlStripeApi;
use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\Stripe\StripeException;
use Tds\Frontend\Contract\Stripe\StripeWebhook;
use Tds\Frontend\Contract\Stripe\StripeWebhookDef;
use Tds\Frontend\Contract\Stripe\StripeWebhookSource;
use Tds\Frontend\Contract\UserContext;

/**
 * Open-source projects TDS publishes, and donations to them.
 *
 * Each project has its own small website (the first: `wparted.tracht-digital.de`,
 * repo `tds-wparted-frontend`). The site keeps its own texts and reads only what
 * changes with a release or a decision from here: version, download link, status
 * line, and whether — and in which amounts — it takes donations.
 *
 * ### One site connection per project
 *
 * Resource `opensource/<slug>`, profile `opensource`, scope `/opensource/projects`.
 * A save in the panel sends that site a `project` cache event.
 *
 * ### Three public routes, three different trust levels
 *
 * - `GET /opensource/projects/{slug}` is read by the site's SERVER, so it is
 *   site-key protected ({@see siteKeyRoutes}).
 * - `POST /opensource/donate/{slug}` is called from a visitor's BROWSER, which has
 *   no key and never will. It lives under its own prefix for exactly that
 *   reason: under `/opensource/projects` the key middleware would gate it, and
 *   enforcement would turn into an outage of the donate button.
 * - `POST /opensource/stripe-webhook` is Stripe; the signature is the auth.
 *
 * Admin routes live under `/opensource/admin`, outside the key prefix too.
 */
final class OpensourceModule extends AbstractModule implements ApiDocSource, SiteKeyProtected, StripeWebhookSource, SetupStatusSource
{
    use ModuleHttp;

    public const RESOURCE_TYPE = 'opensource';
    public const PROFILE = 'opensource';
    public const SCOPES = ['/opensource/projects'];

    /** Settings namespace. */
    private const NS = 'opensource';

    /** Checkout sessions one address may start per hour. */
    private const RATE_LIMIT = 10;

    private const SLUG = '{slug:[a-z0-9-]+}';

    private ?ContainerInterface $setupContainer = null;

    public function id(): string
    {
        return 'opensource';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('opensource:read', 'Open-Source-Projekte und Spenden ansehen', 'opensource'),
            new PermissionDef('opensource:write', 'Open-Source-Projekte bearbeiten', 'opensource'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    /** @return SettingDef[] */
    public function settings(): array
    {
        return [
            new SettingDef('stripe_webhook_secret', 'Stripe Webhook Secret (Spenden)', true, 'opensource'),
        ];
    }

    /** Listed in the admin panel under Einstellungen → Zahlungen (Stripe). */
    public function stripeWebhooks(): array
    {
        return [new StripeWebhookDef(
            'Spenden (Open Source)',
            '/opensource/stripe-webhook',
            [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'checkout.session.expired',
            ],
            self::NS,
            'stripe_webhook_secret',
        )];
    }

    /** @return list<array<string,string>> */
    public function setupItems(UserContext $user): array
    {
        $c = $this->setupContainer;
        if ($c === null) {
            return [];
        }
        $items = [];
        try {
            $items[] = [
                'id' => 'opensource:stripe-webhook',
                'module' => 'opensource',
                'title' => 'Spenden: Stripe-Webhook',
                'description' => 'Ohne Webhook-Geheimnis meldet Stripe keine Spende zurück: die Spendenliste bleibt leer, obwohl Geld eingeht.',
                'state' => self::webhookSecret($c) !== '' ? 'ok' : 'missing',
                'level' => 'recommended',
                'href' => '/einstellungen#settings-stripe',
            ];
        } catch (\Throwable) {
        }
        return $items;
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        $this->setupContainer = $c;
        if ($c === null) {
            return;
        }
        // Bound UNCONDITIONALLY: PHP-DI's has() is true for every concrete
        // class through autowiring, so an `if (!has())` guard skips the binding.
        if (method_exists($c, 'set')) {
            $c->set(ProjectRepository::class, static fn ($c) => new ProjectRepository($c->get(PDO::class)));
            $c->set(DonationRepository::class, static fn ($c) => new DonationRepository($c->get(PDO::class)));
        }

        $this->registerPublic($app, $c);
        $this->registerAdmin($app, $c);
    }

    /* ==================================================================== */
    /* public                                                                */
    /* ==================================================================== */

    private function registerPublic(App $app, ContainerInterface $c): void
    {
        $app->get('/opensource/projects/' . self::SLUG, function (Request $req, Response $res, array $args) use ($c): Response {
            try {
                $row = $c->get(ProjectRepository::class)->findPublished((string) $args['slug']);
                return $row === null
                    ? self::json($res, ['project' => null], 404)
                    : self::json($res, ['project' => ProjectRepository::publicShape($row, self::stripe($c)->isConfigured())]);
            } catch (\Throwable $e) {
                // Fail soft: the site has baked values for every field, and a 404
                // is never cached by it.
                error_log('[opensource] public project read failed: ' . $e->getMessage());
                return self::json($res, ['project' => null], 404);
            }
        });

        $app->post('/opensource/donate/' . self::SLUG, function (Request $req, Response $res, array $args) use ($c): Response {
            try {
                $row = $c->get(ProjectRepository::class)->findPublished((string) $args['slug']);
            } catch (\Throwable $e) {
                error_log('[opensource] donate lookup failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'unavailable'], 503);
            }
            if ($row === null) {
                return self::json($res, ['error' => 'not_found'], 404);
            }
            $stripe = self::stripe($c);
            if (!$row['donations_enabled'] || !$stripe->isConfigured() || ($row['site_url'] ?? '') === '') {
                return self::json($res, ['error' => 'donations_disabled'], 503);
            }

            $body = (array) $req->getParsedBody();
            $amount = DonationAmount::parse($body['amount'] ?? null, $row['donation_min'], $row['donation_max']);
            if (is_string($amount)) {
                [$min, $max] = DonationAmount::limits($row['donation_min'], $row['donation_max']);
                return self::json($res, ['error' => $amount, 'min' => $min, 'max' => $max], 422);
            }
            $lang = ($body['lang'] ?? 'de') === 'en' ? 'en' : 'de';

            $donations = $c->get(DonationRepository::class);
            $ipHash = ClientIp::hash($req);
            if ($ipHash !== null && $donations->recentFromIp($ipHash) >= self::RATE_LIMIT) {
                return self::json($res, ['error' => 'rate_limited'], 429);
            }

            try {
                $session = $stripe->post('/checkout/sessions', DonationCheckout::params($row, $amount, $lang));
            } catch (StripeException $e) {
                error_log('[opensource] checkout failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'payment_provider'], 502);
            }
            $sessionId = (string) ($session['id'] ?? '');
            $url = (string) ($session['url'] ?? '');
            if ($sessionId === '' || $url === '') {
                return self::json($res, ['error' => 'payment_provider'], 502);
            }
            $donations->open($row['id'], $sessionId, $amount, (string) $row['currency'], $lang, $ipHash);
            return self::json($res, ['url' => $url], 201);
        });

        $app->post('/opensource/stripe-webhook', function (Request $req, Response $res) use ($c): Response {
            $secret = self::webhookSecret($c);
            if ($secret === '') {
                return self::json($res, ['error' => 'Webhook secret not configured'], 503);
            }
            $payload = (string) $req->getBody();
            if (!StripeWebhook::verify($payload, $req->getHeaderLine('Stripe-Signature'), $secret)) {
                return self::json($res, ['error' => 'Invalid signature'], 400);
            }
            $event = json_decode($payload, true);
            $change = is_array($event) ? DonationEvent::fromStripe($event) : null;
            if ($change !== null) {
                $c->get(DonationRepository::class)->settle($change['session'], $change['status']);
            }
            // Always 200 for a verified event, ours or not: the account is shared,
            // and a non-2xx makes Stripe retry another module's event for days.
            return self::json($res, ['received' => true]);
        });
    }

    /* ==================================================================== */
    /* admin                                                                 */
    /* ==================================================================== */

    private function registerAdmin(App $app, ContainerInterface $c): void
    {
        $app->get('/opensource/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, [
                'projects' => count($c->get(ProjectRepository::class)->all()),
                'donations' => $c->get(DonationRepository::class)->summary(),
            ]);
        });

        $app->get('/opensource/admin/projects', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, [
                'projects' => $c->get(ProjectRepository::class)->all(),
                'paymentsReady' => self::stripe($c)->isConfigured(),
                'webhookReady' => self::webhookSecret($c) !== '',
            ]);
        });

        $app->post('/opensource/admin/projects', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(ProjectRepository::class);
            $fields = ProjectFields::validate((array) $req->getParsedBody(), null);
            if (is_string($fields)) {
                return self::json($res, ['error' => $fields], 422);
            }
            if ($repo->find($fields['slug']) !== null) {
                return self::json($res, ['error' => 'Ein Projekt mit dieser Adresse gibt es schon.'], 409);
            }
            $repo->create($fields);
            return self::json($res, ['project' => $repo->find($fields['slug'])], 201);
        });

        $app->get('/opensource/admin/projects/' . self::SLUG, function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:read', $res)) !== null) {
                return $deny;
            }
            $row = $c->get(ProjectRepository::class)->find((string) $args['slug']);
            if ($row === null) {
                return self::json($res, ['error' => 'not_found'], 404);
            }
            return self::json($res, [
                'project' => $row,
                'donations' => $c->get(DonationRepository::class)->summary($row['id']),
            ]);
        });

        $app->put('/opensource/admin/projects/' . self::SLUG, function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(ProjectRepository::class);
            $slug = (string) $args['slug'];
            $existing = $repo->find($slug);
            if ($existing === null) {
                return self::json($res, ['error' => 'not_found'], 404);
            }
            $fields = ProjectFields::validate((array) $req->getParsedBody(), $existing);
            if (is_string($fields)) {
                return self::json($res, ['error' => $fields], 422);
            }
            $repo->update($slug, $fields);
            return self::json($res, [
                'project' => $repo->find($slug),
                'cache' => self::refreshSite($c, $slug),
            ]);
        });

        $app->get('/opensource/admin/donations', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:read', $res)) !== null) {
                return $deny;
            }
            $query = $req->getQueryParams();
            $status = (string) ($query['status'] ?? 'paid');
            $status = in_array($status, ['open', 'paid', 'expired', 'failed'], true) ? $status : null;
            $donations = $c->get(DonationRepository::class);
            return self::json($res, [
                'donations' => $donations->recent((int) ($query['limit'] ?? 100), $status),
                'summary' => $donations->summary(),
            ]);
        });

        $app->get('/opensource/admin/projects/' . self::SLUG . '/connection', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:read', $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            $connection = $connections->get(self::RESOURCE_TYPE, (string) $args['slug']);
            return $connection === null
                ? self::json($res, ['error' => 'Connection not found'], 404)
                : self::json($res, ['connection' => $connection->toArray()]);
        });

        $app->delete('/opensource/admin/projects/' . self::SLUG . '/connection', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:write', $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            return self::json($res, [
                'ok' => true,
                'deleted' => $connections->delete(self::RESOURCE_TYPE, (string) $args['slug']),
            ]);
        });

        $app->post('/opensource/admin/projects/' . self::SLUG . '/connection/pairing', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:write', $res)) !== null) {
                return $deny;
            }
            $slug = (string) $args['slug'];
            $project = $c->get(ProjectRepository::class)->find($slug);
            if ($project === null) {
                return self::json($res, ['error' => 'not_found'], 404);
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            $body = (array) $req->getParsedBody();
            $origin = trim((string) ($body['origin'] ?? ($project['site_url'] ?? '')));
            try {
                $pairing = $connections->createPairing(
                    self::RESOURCE_TYPE,
                    $slug,
                    $origin,
                    self::PROFILE,
                    [self::RESOURCE_TYPE => $slug],
                    self::SCOPES,
                );
                return self::json($res, $connections->deliverPairing($pairing, self::apiBase($req))->toArray(), 201);
            } catch (SiteConnectionException $e) {
                return self::json($res, ['error' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
            } catch (\Throwable $e) {
                error_log('[opensource] pairing failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'Pairing could not be created'], 503);
            }
        });

        $app->post('/opensource/admin/projects/' . self::SLUG . '/cache/rebuild', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'opensource:write', $res)) !== null) {
                return $deny;
            }
            $report = self::refreshSite($c, (string) $args['slug']);
            $status = match ($report['cache_status']) {
                'refreshed' => 202,
                'not_configured' => 503,
                default => 502,
            };
            return self::json($res, ['cache' => $report], $status);
        });
    }

    /* ==================================================================== */
    /* helpers                                                               */
    /* ==================================================================== */

    /**
     * Ask the project's site to re-render. Semantic event, never paths: which
     * URLs show the version is the site's knowledge.
     *
     * `not_configured` is the normal state before pairing and must read as such
     * in the panel — neither success nor failure.
     *
     * @return array{cache_status:string,cached:bool}
     */
    private static function refreshSite(ContainerInterface $c, string $slug): array
    {
        try {
            $connection = self::connections($c)?->get(self::RESOURCE_TYPE, $slug);
            if ($connection === null || !$c->has(ConnectedSiteCache::class)) {
                return ['cache_status' => 'not_configured', 'cached' => false];
            }
            $result = $c->get(ConnectedSiteCache::class)
                ->refresh(self::RESOURCE_TYPE, $slug, new CacheEvent('project', $slug))
                ->toArray();
            return ['cache_status' => (string) ($result['cache_status'] ?? 'failed'), 'cached' => (bool) ($result['cached'] ?? false)] + $result;
        } catch (\Throwable $e) {
            error_log('[opensource] page-cache request failed: ' . $e->getMessage());
            return ['cache_status' => 'failed', 'cached' => false];
        }
    }

    private static function stripe(ContainerInterface $c): StripeApi
    {
        try {
            $central = $c->has(StripeApi::class) ? $c->get(StripeApi::class) : null;
        } catch (\Throwable) {
            $central = null;
        }
        return $central instanceof StripeApi
            ? $central
            : new CurlStripeApi((string) (getenv('STRIPE_SECRET_KEY') ?: ''));
    }

    /**
     * The signing secret, from the settings store only. No env fallback: a new
     * env var would need a decision in the gateway's installer, and the panel
     * is where Einstellungen → Zahlungen asks for it anyway.
     */
    private static function webhookSecret(ContainerInterface $c): string
    {
        try {
            $store = $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
            return $store instanceof SettingsStore ? (string) ($store->getSecret(self::NS, 'stripe_webhook_secret') ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function connections(ContainerInterface $c): ?SiteConnections
    {
        try {
            return $c->has(SiteConnections::class) ? $c->get(SiteConnections::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function apiBase(Request $req): string
    {
        $uri = $req->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    /** @return list<array<string, mixed>> */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }

    /**
     * Only what a project site's SERVER reads. Never `/opensource/donate` (a
     * browser call) and never `/opensource` as a whole.
     *
     * @return list<string>
     */
    public function siteKeyRoutes(): array
    {
        return self::SCOPES;
    }
}
