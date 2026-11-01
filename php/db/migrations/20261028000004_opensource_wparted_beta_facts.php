<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Brings the seeded WParted row to its documented state: beta, Windows 11 only,
 * real disks locked; no mirror and no issue tracker to link, because bugs now go
 * through the site's form and by e-mail.
 *
 * Each field changes only while it still holds the seed's value, so nothing edited
 * in the panel is overwritten.
 */
final class OpensourceWpartedBetaFacts extends AbstractMigration
{
    private const SEED_MIRROR = 'https://git.tracht-digital.de/jtracht/WParted';
    private const SEED_ISSUES = 'https://git.tracht-digital.de/jtracht/WParted-PM/issues';

    public function up(): void
    {
        $changes = [
            ['version_date', '2026-10-28', '2026-11-01'],
            [
                'tagline_de',
                'Freier Partitionsmanager für Windows 7 bis 11 nach dem Vorbild von GParted.',
                'Freier Open-Source-Partitionsmanager für Windows 11 mit GParted-ähnlicher Bedienung.',
            ],
            [
                'tagline_en',
                'Free partition manager for Windows 7 to 11, modelled on GParted.',
                'Free, open-source partition manager for Windows 11 with a GParted-style workflow.',
            ],
            [
                'status_de',
                'Alpha: plant Änderungen für jeden Datenträger und wendet sie nur auf Image-Dateien an. Physische Datenträger werden nie beschrieben.',
                'Beta (0.8.0-beta.1): schreibt nur auf Image-Dateien und angehängte virtuelle Datenträger. Echte Datenträger bleiben gesperrt. Der neueste Download kann älter sein als dieser Stand.',
            ],
            [
                'status_en',
                'Alpha: plans changes for every disk and applies them to image files only. Physical disks are never written.',
                'Beta (0.8.0-beta.1): writes only to image files and attached virtual disks. Real disks stay locked. The newest download can be older than this state.',
            ],
        ];
        foreach ($changes as [$column, $seed, $now]) {
            $this->execute(
                "UPDATE oss_project SET {$column} = ? WHERE slug = 'wparted' AND {$column} = ?",
                [$now, $seed],
            );
        }
        $this->execute("UPDATE oss_project SET mirror_url = NULL WHERE slug = 'wparted' AND mirror_url = ?", [self::SEED_MIRROR]);
        $this->execute("UPDATE oss_project SET issues_url = NULL WHERE slug = 'wparted' AND issues_url = ?", [self::SEED_ISSUES]);
    }

    public function down(): void
    {
        // Facts, not schema: nothing to take back.
    }
}
