<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Tds\Ext\Opensource\OpensourceModule;
use Tds\Ext\Opensource\Tests\Support\TestContainer;
use Tds\Frontend\Contract\ModuleRegistry;

/**
 * The route documentation this module contributes to the admin frontend's API
 * reference (`GET /wiki.json`).
 *
 * Prose that sits next to code rots, and a reference full of confident, wrong
 * detail is worse than the bare route list it replaced. So the documented set
 * and the registered set are asserted to be the SAME set: renaming a path fails
 * here instead of quietly leaving a description behind (or a blank row).
 */
final class OpensourceApiDocsTest extends TestCase
{
    /** @return string[] "<METHOD> <pattern>" for every route the module mounts */
    private static function mountedRoutes(): array
    {
        // The module binds its repositories in `register()`, so it needs a
        // container that can `set()`; mounting never runs a handler.
        $app = AppFactory::create(null, new TestContainer());
        $registry = new ModuleRegistry([new OpensourceModule()]);
        $registry->registerAll($app);
        return array_keys($registry->routeOwners());
    }

    /** @return string[] */
    private static function documentedRoutes(): array
    {
        return array_map(
            static fn (array $doc): string => strtoupper((string) $doc['method']) . ' ' . $doc['pattern'],
            (new OpensourceModule())->apiDocs(),
        );
    }

    public function testDocumentsExactlyTheRoutesItMounts(): void
    {
        $mounted = self::mountedRoutes();
        $documented = self::documentedRoutes();
        sort($mounted);
        sort($documented);

        self::assertSame($mounted, $documented);
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $permissions = array_map(
            static fn ($p): string => $p->id,
            (new OpensourceModule())->permissions(),
        );

        foreach ((new OpensourceModule())->apiDocs() as $doc) {
            $where = $doc['method'] . ' ' . $doc['pattern'];

            // Shown collapsed, so it has to stand on its own.
            self::assertNotSame('', trim((string) $doc['summary']), "Leere Zusammenfassung: {$where}");
            // Parenthesised deliberately: `??` binds tighter than `?:`, so the
            // unbracketed form collapses to a constant and asserts nothing.
            $auth = $doc['auth'] ?? (isset($doc['permission']) ? 'permission' : 'public');
            self::assertContains(
                $auth,
                ['public', 'session', 'permission', 'admin', 'token'],
                "Unbekannter auth-Wert: {$where}",
            );
            if (isset($doc['permission'])) {
                // A reference that names a permission nobody can grant is a
                // wrong answer to "why do I get a 403 here".
                self::assertContains($doc['permission'], $permissions, "Unbekannte Permission: {$where}");
            }
            foreach ($doc['params'] ?? [] as $param) {
                self::assertContains($param['in'], ['path', 'query', 'body', 'header'], "Unbekanntes in: {$where}");
                self::assertNotSame('', trim((string) $param['name']), "Parameter ohne Namen: {$where}");
            }
            foreach ($doc['responses'] ?? [] as $response) {
                self::assertIsInt($response['status'], "Status ist kein int: {$where}");
                self::assertNotSame('', trim((string) $response['description']), "Antwort ohne Text: {$where}");
            }
        }
    }

    public function testEveryPathPlaceholderIsDocumented(): void
    {
        // A `{id}` nobody explains is the most common gap: the pattern shows it
        // exists, the reference has to say what goes in it.
        //
        // Collected and asserted ONCE rather than inside the loop: a module
        // whose routes carry no placeholder would otherwise perform no
        // assertion at all — which reads as "checked" but is not (and trips
        // phpunit's failOnRisky). This also reports every gap instead of
        // stopping at the first.
        $missing = [];
        foreach ((new OpensourceModule())->apiDocs() as $doc) {
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)/', (string) $doc['pattern'], $matches);
            $documented = array_column(
                array_filter($doc['params'] ?? [], static fn (array $p): bool => $p['in'] === 'path'),
                'name',
            );
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, $documented, true)) {
                    $missing[] = "{$doc['method']} {$doc['pattern']} → {$placeholder}";
                }
            }
        }

        self::assertSame([], $missing, 'Undokumentierte Pfadparameter');
    }
}
