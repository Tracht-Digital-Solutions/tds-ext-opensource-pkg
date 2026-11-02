<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Domain;

use PDO;

/**
 * Release notes rewritten in the panel, one row per project and version. A language
 * left empty falls back to GitHub's text; a row with no text at all is deleted.
 */
final class ReleaseNoteRepository
{
    public const MAX = 20000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{de:?string,en:?string}|null */
    public function find(int $projectId, string $version): ?array
    {
        $stmt = $this->pdo->prepare('SELECT notes_de, notes_en FROM oss_release_note WHERE project_id = :p AND version = :v LIMIT 1');
        $stmt->execute([':p' => $projectId, ':v' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['de' => $row['notes_de'], 'en' => $row['notes_en']];
    }

    /** @return array<string,array{de:?string,en:?string}> by version */
    public function all(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT version, notes_de, notes_en FROM oss_release_note WHERE project_id = :p');
        $stmt->execute([':p' => $projectId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['version']] = ['de' => $row['notes_de'], 'en' => $row['notes_en']];
        }
        return $out;
    }

    public function save(int $projectId, string $version, ?string $de, ?string $en): void
    {
        $de = self::clean($de);
        $en = self::clean($en);
        if ($de === null && $en === null) {
            $this->pdo->prepare('DELETE FROM oss_release_note WHERE project_id = :p AND version = :v')
                ->execute([':p' => $projectId, ':v' => $version]);
            return;
        }
        $sql = $this->find($projectId, $version) === null
            ? 'INSERT INTO oss_release_note (project_id, version, notes_de, notes_en) VALUES (:p, :v, :de, :en)'
            : 'UPDATE oss_release_note SET notes_de = :de, notes_en = :en WHERE project_id = :p AND version = :v';
        $this->pdo->prepare($sql)->execute([':p' => $projectId, ':v' => $version, ':de' => $de, ':en' => $en]);
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);
        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }
}
