<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Domain;

use PDO;
use Tds\Ext\Opensource\Support\DonationAmount;

/**
 * Every query on `oss_project`. Arrays, not entities — the house style.
 *
 * The public read filters `published` **in SQL**: an unpublished project must
 * not be in the result set at all.
 */
final class ProjectRepository
{
    private const COLUMNS = 'id, slug, name, site_url, repo_url, mirror_url, issues_url, license, '
        . 'version, version_date, download_url, tagline_de, tagline_en, status_de, status_en, '
        . 'donations_enabled, donation_presets, donation_min, donation_max, currency, published, '
        . 'created_at, updated_at';

    /** Columns a panel save may write. `slug` only on insert. */
    private const WRITABLE = [
        'name', 'site_url', 'repo_url', 'mirror_url', 'issues_url', 'license', 'version',
        'version_date', 'download_url', 'tagline_de', 'tagline_en', 'status_de', 'status_en',
        'donations_enabled', 'donation_presets', 'donation_min', 'donation_max', 'currency', 'published',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> raw rows for the panel */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM oss_project ORDER BY name, slug')
            ?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'typed'], $rows);
    }

    /** @return array<string,mixed>|null raw row */
    public function find(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM oss_project WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::typed($row);
    }

    /** @return array<string,mixed>|null raw row, published only */
    public function findPublished(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM oss_project WHERE slug = :s AND published = 1 LIMIT 1'
        );
        $stmt->execute([':s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::typed($row);
    }

    /** @param array<string,mixed> $fields validated by ProjectFields, incl. slug */
    public function create(array $fields): void
    {
        $columns = array_merge(['slug'], array_values(array_intersect(self::WRITABLE, array_keys($fields))));
        $sql = sprintf(
            'INSERT INTO oss_project (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->pdo->prepare($sql)->execute(self::bind($fields, $columns));
    }

    /** @param array<string,mixed> $fields validated by ProjectFields */
    public function update(string $slug, array $fields): void
    {
        $columns = array_values(array_intersect(self::WRITABLE, array_keys($fields)));
        if ($columns === []) {
            return;
        }
        $sql = sprintf(
            'UPDATE oss_project SET %s WHERE slug = :slug_key',
            implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", $columns)),
        );
        $params = self::bind($fields, $columns);
        $params[':slug_key'] = $slug;
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * The shape the public site reads. Never the panel row: no ids, no
     * timestamps, no unpublished flags.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function publicShape(array $row, bool $paymentsReady): array
    {
        [$min, $max] = DonationAmount::limits((int) $row['donation_min'], (int) $row['donation_max']);
        return [
            'slug' => $row['slug'],
            'name' => $row['name'],
            'siteUrl' => $row['site_url'],
            'repoUrl' => $row['repo_url'],
            'mirrorUrl' => $row['mirror_url'],
            'issuesUrl' => $row['issues_url'],
            'license' => $row['license'],
            'version' => $row['version'],
            'versionDate' => $row['version_date'],
            'downloadUrl' => $row['download_url'],
            'tagline' => ['de' => $row['tagline_de'], 'en' => $row['tagline_en']],
            'status' => ['de' => $row['status_de'], 'en' => $row['status_en']],
            'donations' => [
                // Both switches: the project's, and a Stripe account that can
                // actually take the money. A button that leads to a 503 is worse
                // than no button.
                'enabled' => (bool) $row['donations_enabled'] && $paymentsReady,
                'currency' => $row['currency'],
                'min' => $min,
                'max' => $max,
                'presets' => DonationAmount::presets((string) $row['donation_presets'], $min, $max),
            ],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function typed(array $row): array
    {
        foreach (['id', 'donation_min', 'donation_max'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['donations_enabled', 'published'] as $key) {
            $row[$key] = (bool) (int) $row[$key];
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string>        $columns
     * @return array<string,mixed>
     */
    private static function bind(array $fields, array $columns): array
    {
        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $fields[$column] ?? null;
        }
        return $params;
    }
}
