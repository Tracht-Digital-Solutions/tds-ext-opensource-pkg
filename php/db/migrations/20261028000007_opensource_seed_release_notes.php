<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Plain-language notes for WParted's versions so far, in both languages. They are
 * ordinary panel texts: emptying one in the panel brings GitHub's text back.
 */
final class OpensourceSeedReleaseNotes extends AbstractMigration
{
    private const NOTES = [
        '0.3.0-alpha' => [
            "- Unterbrochene Verschiebungen lassen sich nach einem Absturz oder Stromausfall fortsetzen.\n- Auf Image-Dateien lässt sich ext2/3/4 anlegen, in der Größe ändern und prüfen, NTFS anlegen und prüfen.\n- Verlorene Partitionen lassen sich aus der Suche heraus neu anlegen.",
            "- Interrupted moves can resume after a crash or power loss.\n- On image files, ext2/3/4 can be created, resized and checked, and NTFS created and checked.\n- Lost partitions can be recreated straight from the search.",
        ],
        '0.4.0-alpha' => [
            "- Erster Test mit Administratorrechten an echter Hardware, nur lesend: Die Partitionen stimmten mit der Anzeige von Windows überein.\n- Die Suche nach verlorenen Partitionen läuft auch auf echten Datenträgern, nur lesend.\n- Ein getrennter Hilfsprozess führt Pläne aus, vorerst nur auf Image-Dateien.\n- Echte Datenträger werden weiterhin nie beschrieben.",
            "- First test with administrator rights on real hardware, read-only: the partitions matched what Windows shows.\n- The search for lost partitions also runs on real disks, read-only.\n- A separate helper process executes plans, for now on image files only.\n- Real disks are still never written.",
        ],
        '0.5.0-alpha' => [
            "- VHD-Dateien fester Größe lassen sich öffnen, planen und ändern und bleiben dabei gültig.\n- Dynamische VHD- und VHDX-Dateien werden mit Begründung abgelehnt, statt falsch gelesen zu werden.\n- Anwenden und Fortsetzen laufen über einen eigenen Hilfsprozess. Das Hauptprogramm schreibt die Datei nicht mehr selbst.\n- Echte Datenträger werden weiterhin nie beschrieben.",
            "- Fixed-size VHD files can be opened, planned and changed, and they stay valid.\n- Dynamic VHD and VHDX files are refused with a reason instead of being misread.\n- Applying and resuming run through a separate helper process. The main program no longer writes the file itself.\n- Real disks are still never written.",
        ],
        '0.8.0-beta.1' => [
            "- Änderungen lassen sich jetzt auch auf angehängte virtuelle Datenträger (VHD, VHDX) schreiben. Neu ist auch das Ein- und Aushängen von Laufwerken. Beides ist gebaut, die Prüfung von Hand steht noch aus.\n- Beim Verkleinern von NTFS, exFAT und ext2/3/4 verschiebt WParted die Daten selbst aus dem wegfallenden Bereich.\n- NTFS wird davor einmal komplett simuliert. Passt etwas nicht, stoppt WParted vor der ersten Änderung.\n- btrfs und XFS lassen sich vergrößern.\n- Neues Aussehen in Hell und Dunkel, mit Parti als Programmsymbol.\n- WParted läuft nur noch unter Windows 11.\n- Echte Datenträger bleiben gesperrt.",
            "- Changes can now also be written to attached virtual disks (VHD, VHDX). Mounting and unmounting drives is new as well. Both are built; the check by hand is still open.\n- When shrinking NTFS, exFAT and ext2/3/4, WParted moves the data out of the area that goes away by itself.\n- NTFS is first simulated completely. If something does not fit, WParted stops before the first change.\n- btrfs and XFS can grow.\n- A new look in light and dark, with Parti as the program icon.\n- WParted now runs on Windows 11 only.\n- Real disks stay locked.",
        ],
    ];

    public function up(): void
    {
        foreach (self::NOTES as $version => [$de, $en]) {
            $this->execute(
                'INSERT INTO oss_release_note (project_id, version, notes_de, notes_en)
                 SELECT p.id, ?, ?, ? FROM oss_project p
                 WHERE p.slug = \'wparted\' AND NOT EXISTS (
                     SELECT 1 FROM oss_release_note n WHERE n.project_id = p.id AND n.version = ?
                 )',
                [$version, $de, $en, $version],
            );
        }
    }

    public function down(): void
    {
        // Panel texts by now: nothing to take back.
    }
}
