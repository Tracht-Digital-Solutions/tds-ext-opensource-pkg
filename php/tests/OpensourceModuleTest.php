<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Opensource\OpensourceModule;
use Tds\Ext\Opensource\Support\GithubReleases;
use Tds\Ext\Opensource\Support\ReleaseSource;
use Tds\Ext\Opensource\Tests\Support\TestContainer;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\SettingsStore;
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
            published INTEGER DEFAULT 0, release_mode TEXT DEFAULT \'manual\', release_body TEXT, release_url TEXT,
            release_checked_at TEXT, release_error TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE oss_release_note (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, version TEXT, notes_de TEXT, notes_en TEXT,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (project_id, version))');
        $this->pdo->exec('CREATE TABLE oss_donation (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, stripe_session_id TEXT UNIQUE, amount INTEGER,
            currency TEXT, lang TEXT, status TEXT DEFAULT \'open\', ip_hash TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, paid_at TEXT)');
        $this->pdo->exec("INSERT INTO oss_project (slug, name, site_url, repo_url, license, version, published, donations_enabled)
            VALUES ('wparted', 'WParted', 'https://wparted.tracht-digital.de', 'https://git.tracht-digital.de/jtracht/WParted',
            'GPL-2.0-or-later', '0.3.0-alpha', 1, 1),
            ('secret', 'Secret', NULL, 'https://example.org', 'MIT', NULL, 0, 0)");
    }

    /** @param list<array<string,mixed>> $releases */
    private function githubProject(string $mode): void
    {
        $this->pdo->exec("UPDATE oss_project SET repo_url = 'https://github.com/jtracht/WParted', release_mode = '{$mode}' WHERE slug = 'wparted'");
    }

    private function releases(): ReleaseSource
    {
        return new class implements ReleaseSource {
            public int $calls = 0;

            public function releases(string $repoUrl): array
            {
                $this->calls++;
                return [
                    ['version' => '0.5.0', 'date' => '2026-11-05', 'url' => 'https://github.com/jtracht/WParted/releases/tag/v0.5.0', 'body' => 'Notes five', 'prerelease' => true],
                    ['version' => '0.4.0', 'date' => '2026-11-01', 'url' => 'https://github.com/jtracht/WParted/releases/tag/v0.4.0', 'body' => 'Notes four', 'prerelease' => true],
                ];
            }
        };
    }

    private function app(UserContext $user, ?StripeApi $stripe = null, string $webhookSecret = '', ?ReleaseSource $releases = null, string $githubSecret = ''): App
    {
        $container = new TestContainer([
            PDO::class => $this->pdo,
            UserContext::class => $user,
            SettingsStore::class => self::settings($webhookSecret, $githubSecret),
        ]);
        if ($releases !== null) {
            $container->set(ReleaseSource::class, $releases);
        }
        if ($stripe !== null) {
            $container->set(StripeApi::class, $stripe);
        }
        $app = AppFactory::create(null, $container);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        (new ModuleRegistry([new OpensourceModule()]))->registerAll($app);
        return $app;
    }

    private static function settings(string $webhookSecret, string $githubSecret = ''): SettingsStore
    {
        return new class ($webhookSecret, $githubSecret) implements SettingsStore {
            public function __construct(private readonly string $secret, private readonly string $github)
            {
            }

            public function get(string $namespace, string $key, ?string $default = null): ?string
            {
                return $default;
            }

            public function getSecret(string $namespace, string $key): ?string
            {
                if ($namespace === 'opensource' && $key === 'github_webhook_secret') {
                    return $this->github !== '' ? $this->github : null;
                }
                return $namespace === 'opensource' && $key === 'stripe_webhook_secret' && $this->secret !== '' ? $this->secret : null;
            }

            public function set(string $namespace, string $key, string $value, bool $secret): void
            {
            }

            public function delete(string $namespace, string $key): void
            {
            }

            public function allMasked(string $namespace): array
            {
                return [];
            }
        };
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
        $unset = $this->app($this->user(false), $this->stripe(true));
        self::assertSame(503, $unset->handle(self::post('/opensource/stripe-webhook', []))->getStatusCode());

        $set = $this->app($this->user(false), $this->stripe(true), 'whsec_test');
        self::assertSame(400, $set->handle(self::post('/opensource/stripe-webhook', ['type' => 'x']))->getStatusCode());
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

        $response = $this->app($this->user(false), $this->stripe(true), 'whsec_test')->handle($request);
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

    private function publicProject(ReleaseSource $releases): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/opensource/projects/wparted');
        return self::decode($this->app($this->user(false), $this->stripe(false), '', $releases)->handle($request))['project'];
    }

    public function testAutoModeShowsTheNewestReleaseAndItsNotes(): void
    {
        $this->githubProject('auto');
        $project = $this->publicProject($this->releases());

        self::assertSame('0.5.0', $project['version']);
        self::assertSame('2026-11-05', $project['versionDate']);
        self::assertSame('https://github.com/jtracht/WParted/releases/tag/v0.5.0', $project['downloadUrl']);
        self::assertSame(['text' => 'Notes five', 'lang' => 'en'], $project['releaseNotes']['de']);
        self::assertSame(['text' => 'Notes five', 'lang' => 'en'], $project['releaseNotes']['en']);
    }

    public function testAutoModeAsksGithubAtMostOnceInTheInterval(): void
    {
        $this->githubProject('auto');
        $source = $this->releases();
        $this->publicProject($source);
        $this->publicProject($source);
        self::assertSame(1, $source->calls);
    }

    public function testAGithubFailureKeepsTheShownVersion(): void
    {
        $this->githubProject('auto');
        $broken = new class implements ReleaseSource {
            public function releases(string $repoUrl): array
            {
                throw new \RuntimeException('GitHub hat nicht geantwortet (HTTP 503).');
            }
        };
        self::assertSame('0.3.0-alpha', $this->publicProject($broken)['version']);
        $error = $this->pdo->query("SELECT release_error FROM oss_project WHERE slug = 'wparted'")->fetchColumn();
        self::assertStringContainsString('HTTP 503', (string) $error);
    }

    public function testManualModeShowsTheChosenVersionWithRewrittenNotes(): void
    {
        $this->githubProject('manual');
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/opensource/admin/projects/wparted')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'version' => 'v0.4.0', 'notes_version' => '0.4.0', 'notes_de' => 'Neu geschrieben', 'notes_en' => '',
        ]));
        $source = $this->releases();
        $response = $this->app($this->user(true, ['opensource:write']), $this->stripe(true), '', $source)->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $this->pdo->exec("UPDATE oss_project SET version = '0.4.0' WHERE slug = 'wparted'");
        $project = $this->publicProject($source);
        self::assertSame('0.4.0', $project['version']);
        self::assertSame(['text' => 'Neu geschrieben', 'lang' => 'de'], $project['releaseNotes']['de']);
        self::assertSame(['text' => 'Notes four', 'lang' => 'en'], $project['releaseNotes']['en']);
    }

    public function testAutoModeIgnoresAVersionFromAStaleForm(): void
    {
        $this->githubProject('auto');
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/opensource/admin/projects/wparted')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode(['version' => '9.9.9']));
        $response = $this->app($this->user(true, ['opensource:write']), $this->stripe(true), '', $this->releases())->handle($request);
        self::assertSame('0.5.0', self::decode($response)['project']['version']);
    }

    public function testEmptiedNotesFallBackToGithub(): void
    {
        $this->githubProject('auto');
        $source = $this->releases();
        $id = (int) $this->pdo->query("SELECT id FROM oss_project WHERE slug = 'wparted'")->fetchColumn();
        (new \Tds\Ext\Opensource\Domain\ReleaseNoteRepository($this->pdo))->save($id, '0.5.0', 'Text', null);
        self::assertSame('Text', $this->publicProject($source)['releaseNotes']['de']['text']);
        (new \Tds\Ext\Opensource\Domain\ReleaseNoteRepository($this->pdo))->save($id, '0.5.0', '', '');
        self::assertSame('Notes five', $this->publicProject($source)['releaseNotes']['de']['text']);
    }

    public function testTheGithubWebhookChecksItsSignatureAndRepository(): void
    {
        $this->githubProject('auto');
        $source = $this->releases();
        $send = function (array $event, string $secret, string $type = 'release') use ($source) {
            $payload = (string) json_encode($event);
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/opensource/github-webhook/wparted')
                ->withHeader('X-GitHub-Event', $type)
                ->withHeader('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $payload, $secret));
            $request->getBody()->write($payload);
            return $this->app($this->user(false), null, '', $source, 'geheim')->handle($request);
        };
        $ours = ['repository' => ['html_url' => 'https://github.com/jtracht/WParted']];

        self::assertSame(400, $send($ours, 'falsch')->getStatusCode());
        self::assertSame(200, $send(['repository' => ['html_url' => 'https://github.com/other/repo']], 'geheim')->getStatusCode());
        self::assertSame(200, $send($ours, 'geheim', 'ping')->getStatusCode());
        self::assertSame(0, $source->calls);
        self::assertSame(200, $send($ours, 'geheim')->getStatusCode());
        self::assertSame(1, $source->calls);
    }

    public function testGithubAnswersAreParsedWithoutDrafts(): void
    {
        $parsed = GithubReleases::parse([
            ['tag_name' => 'v1.0.0', 'draft' => true, 'published_at' => '2026-11-03T00:00:00Z'],
            ['tag_name' => 'v0.9.0', 'draft' => false, 'prerelease' => true, 'published_at' => '2026-11-01T10:00:00Z', 'html_url' => 'https://github.com/a/b/releases/tag/v0.9.0', 'body' => ' Text '],
            ['tag_name' => 'v0.8.0', 'draft' => false, 'published_at' => '2026-10-01T10:00:00Z', 'html_url' => 'http://insecure', 'body' => ''],
        ]);
        self::assertSame(['0.9.0', '0.8.0'], array_column($parsed, 'version'));
        self::assertSame('2026-11-01', $parsed[0]['date']);
        self::assertSame('Text', $parsed[0]['body']);
        self::assertNull($parsed[1]['url']);
        self::assertSame('jtracht/WParted', GithubReleases::repoPath('https://github.com/jtracht/WParted.git'));
        self::assertNull(GithubReleases::repoPath('https://git.tracht-digital.de/jtracht/WParted'));
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
