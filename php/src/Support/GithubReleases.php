<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

/**
 * Reads a public repository's releases from the GitHub API, without a token.
 *
 * The list, never `/releases/latest`: that route skips pre-releases, and a young
 * project may have nothing else. "Public" means not a draft; the API hides drafts
 * from anonymous callers, and `parse` drops them anyway.
 */
final class GithubReleases implements ReleaseSource
{
    private const MAX_BODY = 20000;

    public function releases(string $repoUrl): array
    {
        $path = self::repoPath($repoUrl);
        if ($path === null) {
            throw new \RuntimeException('Die Adresse des Quellcodes ist kein GitHub-Repository.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Auf dem Server fehlt cURL.');
        }
        $ch = curl_init("https://api.github.com/repos/{$path}/releases?per_page=100");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'TDS-Opensource-Module (+https://tracht-digital.de)',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status !== 200) {
            throw new \RuntimeException(sprintf('GitHub hat nicht geantwortet (HTTP %d).', $status));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GitHub hat eine unlesbare Antwort geschickt.');
        }
        return self::parse($decoded);
    }

    /** `https://github.com/owner/repo(.git)(/)` → `owner/repo`; null for anything else. */
    public static function repoPath(string $url): ?string
    {
        return preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', trim($url), $m) === 1
            ? "{$m[1]}/{$m[2]}"
            : null;
    }

    /** `v1.2.0` and `1.2.0` are the same version. */
    public static function normalize(string $version): string
    {
        return preg_replace('/^v(?=\d)/', '', trim($version)) ?? '';
    }

    /**
     * @param array<mixed> $decoded the API's answer
     * @return list<array{version:string,date:?string,url:?string,body:?string,prerelease:bool}>
     */
    public static function parse(array $decoded): array
    {
        $out = [];
        foreach ($decoded as $release) {
            if (!is_array($release) || ($release['draft'] ?? true) !== false) {
                continue;
            }
            $version = self::normalize((string) ($release['tag_name'] ?? ''));
            if ($version === '' || mb_strlen($version) > 40) {
                continue;
            }
            $published = (string) ($release['published_at'] ?? '');
            $url = (string) ($release['html_url'] ?? '');
            $body = trim((string) ($release['body'] ?? ''));
            $out[] = [
                'version' => $version,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', $published) === 1 ? substr($published, 0, 10) : null,
                'url' => str_starts_with($url, 'https://') ? mb_substr($url, 0, 300) : null,
                'body' => $body === '' ? null : mb_substr($body, 0, self::MAX_BODY),
                'prerelease' => ($release['prerelease'] ?? false) === true,
                'published' => $published,
            ];
        }
        // Newest first by publication, not by the API's order.
        usort($out, static fn (array $a, array $b): int => strcmp($b['published'], $a['published']));
        return array_map(static function (array $r): array {
            unset($r['published']);
            return $r;
        }, $out);
    }
}
