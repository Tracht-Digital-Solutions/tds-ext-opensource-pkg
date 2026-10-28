<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One open-source project TDS publishes: the facts its website shows, and
 * whether it takes donations.
 *
 * The site has its own copy of every text, so this table holds only what changes
 * with a release or a decision: the version, where to download it, a short
 * status line, the donation switch and amounts. A release then costs a row
 * update and a cache rebuild, not a site deployment.
 *
 * `slug` doubles as the site connection's resource id (`opensource/<slug>`), so
 * it is immutable once a site is paired with it.
 *
 * Amounts are cents. `donation_presets` is a comma list (`500,1000,2500`) — three
 * numbers do not earn a JSON column.
 */
final class CreateOpensourceProject extends AbstractMigration
{
    public function change(): void
    {
        $this->table('oss_project', ['id' => true, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('slug', 'string', ['limit' => 60])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('site_url', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('repo_url', 'string', ['limit' => 300])
            ->addColumn('mirror_url', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('issues_url', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('license', 'string', ['limit' => 60])
            ->addColumn('version', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('version_date', 'date', ['null' => true])
            ->addColumn('download_url', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('tagline_de', 'string', ['limit' => 240, 'default' => ''])
            ->addColumn('tagline_en', 'string', ['limit' => 240, 'default' => ''])
            ->addColumn('status_de', 'string', ['limit' => 400, 'null' => true])
            ->addColumn('status_en', 'string', ['limit' => 400, 'null' => true])
            // Off by default: a donation button that leads to a 503 is worse than none.
            ->addColumn('donations_enabled', 'boolean', ['default' => false])
            ->addColumn('donation_presets', 'string', ['limit' => 120, 'default' => '500,1000,2500'])
            ->addColumn('donation_min', 'integer', ['signed' => false, 'default' => 200])
            ->addColumn('donation_max', 'integer', ['signed' => false, 'default' => 50000])
            ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('published', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true, 'name' => 'uniq_oss_project_slug'])
            ->create();
    }
}
