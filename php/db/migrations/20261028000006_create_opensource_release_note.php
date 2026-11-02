<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Release notes rewritten in the panel, per version: going back to an older version
 * brings its rewritten text back, and a new version starts from GitHub's text.
 */
final class CreateOpensourceReleaseNote extends AbstractMigration
{
    public function change(): void
    {
        $this->table('oss_release_note', ['id' => true, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('version', 'string', ['limit' => 40])
            ->addColumn('notes_de', 'text', ['null' => true])
            ->addColumn('notes_en', 'text', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['project_id', 'version'], ['unique' => true, 'name' => 'uniq_oss_release_note'])
            ->create();
    }
}
