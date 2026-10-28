<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Opensource\OpensourceModule;
use Tds\Ext\Opensource\Tests\Support\TestContainer;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\UserContext;

/**
 * The routes through a real ModuleRegistry + Slim app, on SQLite with the two
 * tables created by hand (the migrations target MySQL; their own rules are in
 * OpensourceMigrationsTest). Only queries that are portable are exercised here.
 */
final class OpensourceModuleTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE oss_project (
            id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT, site_url TEXT, repo_url TEXT,
            mirror_url TEXT, issues_url TEXT, license TEXT, version TEXT, version_date TEXT, download_url TEXT,
            tagline_de TEXT DEFAULT \'\', tagline_en TEXT DEFAULT \'\', status_de TEXT, status_en TEXT,
            donations_enabled INTEGER DEFAULT 0, donation_presets TEXT DEFAULT \'500,1000,2500\',
            donation_min INTEGER DEFAULT 200, donation_max INTEGER DEFAULT 50000, currency TEXT DEFAULT \'EUR\',
            published INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE oss_donation (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, stripe_session_id TEXT UNIQUE, amount INTEGER,
            currency TEXT, lang TEXT, status TEXT DEFAULT \'open\', ip_hash TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, paid_at TEXT)');
        $this->pdo->exec("INSERT INTO oss_project (slug, name, site_url, repo_url, license, version, published, donations_enabled)
            VALUES ('wparted', 'WParted', 'https://wparted.tracht-digital.de', 'https://git.tracht-digital.de/jtracht/WParted',
            'GPL-2.0-or-later', '0.3.0-alpha', 1, 1),
            ('secret', 'Secret', NULL, 'https://example.org', 'MIT', NULL, 0, 0)");
    }

    private function app(UserContext $user, ?StripeApi $stripe = null): App
    {
        $container = new TestContainer([
            PDO::class => $this->pdo,
            UserContext::class => $user,
        ]);
        if ($stripe !== null) {
            $container->set(StripeApi::class, $stripe);
        }
        $app = AppFactory::create(null, $container);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        (new ModuleRegistry([new OpensourceModule()]))->registerAll($app);
        return $app;
    }

    private function user(bool $auth, array $perms = []): UserContext
    {
        $user = $this->createMock(UserContext::class);
        $user->method('isAuthenticated')->willReturn($auth);
        $user->method('has')->willReturnCallback(static fn (string $p): bool => in_array($p, $perms, true));
        return $user;
    }

    private function stripe(bool $configured, array $session = ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']): StripeApi
    {
        return new class ($configured, $session) implements StripeApi {
            /** @var list<array{string,array<string,mixed>}> */
            public array $calls = [];

            public function __construct(private readonly bool $configured, private readonly array $session)
            {
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function mode(): ?string
            {
                return $this->configured ? 'test' : null;
            }

            public function post(string $path, array $params = [], ?string $idempotencyKey = null): array
            {
                $this->calls[] = [$path, $params];
                return $this->session;
            }

            public function get(string $path, array $query = []): array
            {
                return [];
            }
        };
    }

    /** @return array<string,mixed> */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private static function post(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode($body));
        return $request;
    }

    public function testPublicReadShowsAPublishedProject(): void
    {
        $response = $this->app($this->user(false), $this->stripe(true))
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/opensource/projects/wparted'));
        self::assertSame(200, $response->getStatusCode());
        $project = self::decode($response)['project'];
        self::assertSame('0.3.0-alpha', $project['version']);
        self::assertTrue($project['donations']['enabled']);
        self::assertSame([500, 1000, 2500], $project['donations']['presets']);
        self::assertArrayNotHasKey('id', $project, 'no row ids on the public surface');
    }

    public function testDonationsReadDisabledWithoutStripe(): void
    {
        $response = $this->app($this->user(false), $this->stripe(false))
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/opensource/projects/wparted'));
        self::assertFalse(self::decode($response)['project']['donations']['enabled']);
    }

    public function testAnUnpublishedProjectIsA404(): void
    {
        $app = $this->app($this->user(false), $this->stripe(true));
        foreach (['/opensource/projects/secret', '/opensource/projects/nope'] as $path) {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
            self::assertSame(404, $response->getStatusCode(), $path);
        }
    }

    public function testDonateCreatesACheckoutAndRecordsItOpen(): void
    {
        $stripe = $this->stripe(true);
        $response = $this->app($this->user(false), $stripe)
            ->handle(self::post('/opensource/donate/wparted', ['amount' => 1000, 'lang' => 'en']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', self::decode($response)['url']);
        self::assertSame('/checkout/sessions', $stripe->calls[0][0]);
        self::assertSame('https://wparted.tracht-digital.de/en/donate/thanks', $stripe->calls[0][1]['success_url']);

        $row = $this->pdo->query('SELECT amount, status, lang, ip_hash FROM oss_donation')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['amount' => 1000, 'status' => 'open', 'lang' => 'en'], array_slice($row, 0, 3));
        self::assertNotSame('203.0.113.7', $row['ip_hash'], 'never the raw address');
    }

    public function testDonateRefusesBadAmountsAndDisabledProjects(): void
    {
        $app = $this->app($this->user(false), $this->stripe(true));
        $tooLow = $app->handle(self::post('/opensource/donate/wparted', ['amount' => 50]));
        self::assertSame(422, $tooLow->getStatusCode());
        self::assertSame('amount_too_low', self::decode($tooLow)['error']);

        self::assertSame(404, $app->handle(self::post('/opensource/donate/secret', ['amount' => 1000]))->getStatusCode());

        $noStripe = $this->app($this->user(false), $this->stripe(false));
        self::assertSame(503, $noStripe->handle(self::post('/opensource/donate/wparted', ['amount' => 1000]))->getStatusCode());
    }

    public function testWebhookWithoutSecretOrSignatureIsRefused(): void
    {
        putenv('OPENSOURCE_STRIPE_WEBHOOK_SECRET');
        $app = $this->app($this->user(false), $this->stripe(true));
        self::assertSame(503, $app->handle(self::post('/opensource/stripe-webhook', []))->getStatusCode());

        putenv('OPENSOURCE_STRIPE_WEBHOOK_SECRET=whsec_test');
        try {
            self::assertSame(400, $app->handle(self::post('/opensource/stripe-webhook', ['type' => 'x']))->getStatusCode());
        } finally {
            putenv('OPENSOURCE_STRIPE_WEBHOOK_SECRET');
        }
    }

    public function testASignedPaidEventSettlesTheDonation(): void
    {
        $this->pdo->exec("INSERT INTO oss_donation (project_id, stripe_session_id, amount, currency, lang) VALUES (1, 'cs_paid', 1000, 'EUR', 'de')");
        $payload = (string) json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_paid', 'payment_status' => 'paid',
                'metadata' => ['module' => 'opensource', 'project' => 'wparted'],
            ]],
        ]);
        $t = time();
        $signature = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_test');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/opensource/stripe-webhook')
            ->withHeader('Stripe-Signature', $signature);
        $request->getBody()->write($payload);

        putenv('OPENSOURCE_STRIPE_WEBHOOK_SECRET=whsec_test');
        try {
            $response = $this->app($this->user(false), $this->stripe(true))->handle($request);
        } finally {
            putenv('OPENSOURCE_STRIPE_WEBHOOK_SECRET');
        }
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid', $this->pdo->query("SELECT status FROM oss_donation WHERE stripe_session_id = 'cs_paid'")->fetchColumn());
    }

    public function testAdminRoutesCheckTheirPermission(): void
    {
        $get = (new ServerRequestFactory())->createServerRequest('GET', '/opensource/admin/projects');
        self::assertSame(401, $this->app($this->user(false))->handle($get)->getStatusCode());
        self::assertSame(403, $this->app($this->user(true))->handle($get)->getStatusCode());
        self::assertSame(200, $this->app($this->user(true, ['opensource:read']), $this->stripe(true))->handle($get)->getStatusCode());

        $put = (new ServerRequestFactory())->createServerRequest('PUT', '/opensource/admin/projects/wparted');
        self::assertSame(403, $this->app($this->user(true, ['opensource:read']))->handle($put)->getStatusCode());
    }

    public function testAnUpdateIsStoredAndReportsAnUnpairedSiteHonestly(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/opensource/admin/projects/wparted')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode(['version' => '0.4.0-alpha']));
        $response = $this->app($this->user(true, ['opensource:write']), $this->stripe(true))->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = self::decode($response);
        self::assertSame('0.4.0-alpha', $body['project']['version']);
        self::assertSame('not_configured', $body['cache']['cache_status']);
    }

    public function testTheKeyPrefixNeverCoversTheBrowserRoute(): void
    {
        $prefixes = (new OpensourceModule())->siteKeyRoutes();
        self::assertSame(['/opensource/projects'], $prefixes);
        foreach ($prefixes as $prefix) {
            self::assertStringStartsNotWith($prefix, '/opensource/donate/wparted');
            self::assertStringStartsNotWith($prefix, '/opensource/admin/projects');
        }
    }
}
