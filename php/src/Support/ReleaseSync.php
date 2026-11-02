<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

use Tds\Ext\Opensource\Domain\ProjectRepository;

/**
 * Brings a project's stored release in line with GitHub.
 *
 * - `auto`: the newest public release decides version, date, download and notes.
 * - `manual`: the panel decides version, date and download; GitHub only supplies
 *   the notes of exactly that version, or none when it does not know the version.
 *
 * A failure keeps what is stored: the site goes on showing the last good release,
 * and the panel shows the error.
 */
final class ReleaseSync
{
    /** Seconds between two automatic checks; GitHub allows 60 anonymous calls an hour. */
    public const INTERVAL = 900;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ReleaseSource $source,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @return bool whether what the site shows changed
     */
    public function sync(array $row): bool
    {
        // Stamped first, so parallel readers and a dead GitHub cannot pile up.
        $slug = (string) $row['slug'];
        $this->projects->stampReleaseCheck($slug, null);
        try {
            $releases = $this->source->releases((string) $row['repo_url']);
        } catch (\Throwable $e) {
            $this->projects->stampReleaseCheck($slug, mb_substr($e->getMessage(), 0, 240));
            return false;
        }

        if (($row['release_mode'] ?? 'manual') === 'auto') {
            $latest = $releases[0] ?? null;
            if ($latest === null) {
                $this->projects->stampReleaseCheck($slug, 'GitHub kennt noch keinen öffentlichen Release.');
                return false;
            }
            $next = [
                'version' => $latest['version'],
                'version_date' => $latest['date'],
                'download_url' => $latest['url'] ?? $row['download_url'],
                'release_body' => $latest['body'],
                'release_url' => $latest['url'],
            ];
        } else {
            $wanted = GithubReleases::normalize((string) ($row['version'] ?? ''));
            $match = null;
            foreach ($releases as $release) {
                if ($release['version'] === $wanted) {
                    $match = $release;
                    break;
                }
            }
            $next = [
                'version' => $row['version'],
                'version_date' => $row['version_date'],
                'download_url' => $row['download_url'],
                'release_body' => $match['body'] ?? null,
                'release_url' => $match['url'] ?? null,
            ];
        }

        $changed = false;
        foreach ($next as $key => $value) {
            $changed = $changed || ($row[$key] ?? null) !== $value;
        }
        if ($changed) {
            $this->projects->applyRelease($slug, $next);
        }
        return $changed;
    }
}
