<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Which release a project's site shows, and how it is chosen.
 *
 * `release_mode`: `auto` follows the newest public GitHub release, `manual` keeps
 * the version from the panel. `release_body` and `release_url` belong to the shown
 * version and always come from GitHub. Manual is the default because a project
 * need not live on GitHub; WParted does, and follows its releases from here on.
 */
final class AddOpensourceReleaseTracking extends AbstractMigration
{
    public function up(): void
    {
        $this->table('oss_project')
            ->addColumn('release_mode', 'string', ['limit' => 10, 'default' => 'manual'])
            ->addColumn('release_body', 'text', ['null' => true])
            ->addColumn('release_url', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('release_checked_at', 'datetime', ['null' => true])
            ->addColumn('release_error', 'string', ['limit' => 240, 'null' => true])
            ->update();
        $this->execute("UPDATE oss_project SET release_mode = 'auto' WHERE slug = 'wparted'");
    }

    public function down(): void
    {
        $this->table('oss_project')
            ->removeColumn('release_mode')
            ->removeColumn('release_body')
            ->removeColumn('release_url')
            ->removeColumn('release_checked_at')
            ->removeColumn('release_error')
            ->update();
    }
}
