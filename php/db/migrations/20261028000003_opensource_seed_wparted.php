<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The first project, so the WParted site has something to read the moment the
 * module is live. Facts from the WParted README at v0.4.0-alpha: code, CI and
 * releases on GitHub, the Gitea repository a mirror, issues in WParted-PM.
 *
 * Published, donations OFF: the switch goes on in the panel once the Stripe
 * webhook is registered, not by a deployment.
 *
 * Idempotent (a row with the slug is left alone), and written through the table
 * API only — no adapter internals, see the migrations test.
 */
final class OpensourceSeedWparted extends AbstractMigration
{
    public function up(): void
    {
        if ($this->fetchRow("SELECT id FROM oss_project WHERE slug = 'wparted'") !== false) {
            return;
        }
        $this->table('oss_project')->insert([
            'slug' => 'wparted',
            'name' => 'WParted',
            'site_url' => 'https://wparted.tracht-digital.de',
            'repo_url' => 'https://github.com/jtracht/WParted',
            'mirror_url' => 'https://git.tracht-digital.de/jtracht/WParted',
            'issues_url' => 'https://git.tracht-digital.de/jtracht/WParted-PM/issues',
            'license' => 'GPL-2.0-or-later',
            'version' => '0.4.0-alpha',
            'version_date' => '2026-10-28',
            'download_url' => 'https://github.com/jtracht/WParted/releases',
            'tagline_de' => 'Freier Partitionsmanager für Windows 7 bis 11 nach dem Vorbild von GParted.',
            'tagline_en' => 'Free partition manager for Windows 7 to 11, modelled on GParted.',
            'status_de' => 'Alpha: plant Änderungen für jeden Datenträger und wendet sie nur auf Image-Dateien an. Physische Datenträger werden nie beschrieben.',
            'status_en' => 'Alpha: plans changes for every disk and applies them to image files only. Physical disks are never written.',
            'donations_enabled' => 0,
            'donation_presets' => '500,1000,2500',
            'donation_min' => 200,
            'donation_max' => 50000,
            'currency' => 'EUR',
            'published' => 1,
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM oss_project WHERE slug = 'wparted'");
    }
}
