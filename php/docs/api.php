<?php
/**
 * API documentation for this module's routes, rendered in the admin frontend's
 * API reference (`GET /wiki.json`). `php/tests/OpensourceApiDocsTest.php`
 * asserts documented and mounted routes are the same set; `pattern` is the
 * Slim pattern verbatim.
 */

declare(strict_types=1);

$slug = ['in' => 'path', 'name' => 'slug', 'description' => 'Projekt-Adresse, z. B. `wparted`.'];

return [
    [
        'method' => 'GET',
        'pattern' => '/opensource/projects/{slug:[a-z0-9-]+}',
        'summary' => 'Öffentliche Projektdaten für die Projekt-Webseite',
        'description' => 'Liest der Server der Projekt-Site (Site-Key-geschützt). Nur veröffentlichte Projekte. '
            . '`donations.enabled` ist nur wahr, wenn das Projekt Spenden annimmt UND Stripe eingerichtet ist.',
        'tag' => 'Öffentlich',
        'auth' => 'public',
        'params' => [$slug],
        'responses' => [
            ['status' => 200, 'description' => '`{project: {slug, name, version, downloadUrl, tagline, status, donations, …}}`'],
            ['status' => 404, 'description' => 'Unbekannt, unveröffentlicht oder Lesefehler: `{project: null}`'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/opensource/donate/{slug:[a-z0-9-]+}',
        'summary' => 'Spende starten: Stripe-Checkout-Adresse anlegen',
        'description' => 'Ruft der Browser auf der Projekt-Site auf, deshalb ohne Site-Key und unter eigenem Präfix. '
            . 'Betrag in Cent, geprüft gegen die Grenzen des Projekts. Höchstens 10 Versuche je Adresse und Stunde.',
        'tag' => 'Öffentlich',
        'auth' => 'public',
        'params' => [
            $slug,
            ['in' => 'body', 'name' => 'amount', 'description' => 'Betrag in Cent (Ganzzahl).'],
            ['in' => 'body', 'name' => 'lang', 'description' => '`de` oder `en`; bestimmt Checkout-Sprache und Rücksprungseite.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{url}` — dorthin weiterleiten'],
            ['status' => 422, 'description' => '`{error: invalid_amount|amount_too_low|amount_too_high, min, max}`'],
            ['status' => 429, 'description' => 'Zu viele Versuche'],
            ['status' => 503, 'description' => 'Spenden aus oder Stripe nicht eingerichtet'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/opensource/stripe-webhook',
        'summary' => 'Stripe-Webhook für Spenden',
        'description' => 'Signatur geprüft. Wertet nur Checkout-Sessions mit `metadata.module = opensource` aus, '
            . 'alle anderen werden bestätigt und ignoriert.',
        'tag' => 'Öffentlich',
        'auth' => 'token',
        'responses' => [
            ['status' => 200, 'description' => '`{received: true}`'],
            ['status' => 400, 'description' => 'Ungültige Signatur'],
            ['status' => 503, 'description' => 'Kein Webhook-Geheimnis hinterlegt'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/opensource/summary',
        'summary' => 'Kennzahlen für das Dashboard-Widget',
        'permission' => 'opensource:read',
        'responses' => [['status' => 200, 'description' => '`{projects, donations: [{currency, count, total, last30}]}`']],
    ],
    [
        'method' => 'GET',
        'pattern' => '/opensource/admin/projects',
        'summary' => 'Alle Projekte für die Verwaltung',
        'permission' => 'opensource:read',
        'responses' => [['status' => 200, 'description' => '`{projects, paymentsReady, webhookReady}`']],
    ],
    [
        'method' => 'POST',
        'pattern' => '/opensource/admin/projects',
        'summary' => 'Projekt anlegen',
        'permission' => 'opensource:write',
        'params' => [['in' => 'body', 'name' => 'slug', 'description' => 'Adresse, danach unveränderlich.']],
        'responses' => [
            ['status' => 201, 'description' => '`{project}`'],
            ['status' => 409, 'description' => 'Adresse vergeben'],
            ['status' => 422, 'description' => '`{error}` mit deutschem Hinweis'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/opensource/admin/projects/{slug:[a-z0-9-]+}',
        'summary' => 'Ein Projekt mit Spendensumme',
        'permission' => 'opensource:read',
        'params' => [$slug],
        'responses' => [['status' => 200, 'description' => '`{project, donations}`'], ['status' => 404, 'description' => 'Unbekannt']],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/opensource/admin/projects/{slug:[a-z0-9-]+}',
        'summary' => 'Projekt speichern und die Site neu rendern lassen',
        'permission' => 'opensource:write',
        'params' => [$slug],
        'responses' => [
            ['status' => 200, 'description' => '`{project, cache: {cache_status, cached}}`'],
            ['status' => 422, 'description' => '`{error}`'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/opensource/admin/donations',
        'summary' => 'Neueste Spenden und Summen',
        'permission' => 'opensource:read',
        'params' => [
            ['in' => 'query', 'name' => 'status', 'description' => '`paid` (Standard), `open`, `expired`, `failed` oder `all`.'],
            ['in' => 'query', 'name' => 'limit', 'description' => '1–500, Standard 100.'],
        ],
        'responses' => [['status' => 200, 'description' => '`{donations, summary}` — ohne Session-ID und IP-Hash']],
    ],
    [
        'method' => 'GET',
        'pattern' => '/opensource/admin/projects/{slug:[a-z0-9-]+}/connection',
        'summary' => 'Kopplung der Projekt-Site',
        'permission' => 'opensource:read',
        'params' => [$slug],
        'responses' => [['status' => 200, 'description' => '`{connection}`'], ['status' => 404, 'description' => 'Nicht gekoppelt']],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/opensource/admin/projects/{slug:[a-z0-9-]+}/connection',
        'summary' => 'Kopplung trennen',
        'permission' => 'opensource:write',
        'params' => [$slug],
        'responses' => [['status' => 200, 'description' => '`{ok, deleted}`']],
    ],
    [
        'method' => 'POST',
        'pattern' => '/opensource/admin/projects/{slug:[a-z0-9-]+}/connection/pairing',
        'summary' => 'Kopplung anlegen (Code für /install der Site)',
        'permission' => 'opensource:write',
        'params' => [$slug, ['in' => 'body', 'name' => 'origin', 'description' => 'https-Origin der Site; Standard ist die gespeicherte Webseite.']],
        'responses' => [['status' => 201, 'description' => 'Kopplungsdaten, nur jetzt sichtbar'], ['status' => 422, 'description' => 'Origin ungültig']],
    ],
    [
        'method' => 'POST',
        'pattern' => '/opensource/admin/projects/{slug:[a-z0-9-]+}/cache/rebuild',
        'summary' => 'Seiten der Projekt-Site neu rendern lassen',
        'permission' => 'opensource:write',
        'params' => [$slug],
        'responses' => [
            ['status' => 202, 'description' => 'Neu gerendert'],
            ['status' => 503, 'description' => 'Nicht gekoppelt'],
            ['status' => 502, 'description' => 'Site hat nicht geantwortet'],
        ],
    ],
];
