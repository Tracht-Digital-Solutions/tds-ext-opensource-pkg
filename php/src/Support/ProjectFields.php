<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

/**
 * Validates a project edit from the panel into storable columns.
 *
 * Returns the columns or a German error sentence for the form. URLs must be
 * https (a download link over http is an invitation to swap the binary);
 * `site_url` is an origin without a path, because it is where the donation
 * pages live and what the site pairing is checked against.
 */
final class ProjectFields
{
    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,58}[a-z0-9])?$/';

    /**
     * @param array<string,mixed>      $body
     * @param array<string,mixed>|null $existing the stored row on an update
     * @return array<string,mixed>|string
     */
    public static function validate(array $body, ?array $existing): array|string
    {
        $out = [];

        if ($existing === null) {
            $slug = strtolower(trim((string) ($body['slug'] ?? '')));
            if (preg_match(self::SLUG, $slug) !== 1) {
                return 'Die Adresse (Slug) darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.';
            }
            $out['slug'] = $slug;
        }

        $name = trim((string) ($body['name'] ?? ($existing['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Der Name fehlt oder ist länger als 120 Zeichen.';
        }
        $out['name'] = $name;

        foreach (['repo_url' => true, 'mirror_url' => false, 'issues_url' => false, 'download_url' => false] as $key => $required) {
            $value = self::url($body, $existing, $key);
            if ($value === false) {
                return "{$key} muss eine https-Adresse sein.";
            }
            if ($value === null && $required) {
                return 'Die Adresse des Quellcodes fehlt.';
            }
            $out[$key] = $value;
        }

        $site = self::url($body, $existing, 'site_url');
        if ($site === false || ($site !== null && preg_match('#^https://[^/?\#]+/?$#', $site) !== 1)) {
            return 'Die Webseite muss eine https-Origin ohne Pfad sein.';
        }
        $out['site_url'] = $site === null ? null : rtrim($site, '/');

        $license = trim((string) ($body['license'] ?? ($existing['license'] ?? '')));
        if ($license === '' || preg_match('/^[A-Za-z0-9.+-]{1,60}$/', $license) !== 1) {
            return 'Die Lizenz muss als SPDX-Kennung angegeben werden, z. B. GPL-2.0-or-later.';
        }
        $out['license'] = $license;

        $version = self::text($body, $existing, 'version', 40);
        if ($version !== null && preg_match('/^v?\d+(\.\d+){0,3}([-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            return 'Die Version muss wie 1.2.0 oder 0.3.0-alpha aussehen.';
        }
        $out['version'] = $version;

        $date = self::text($body, $existing, 'version_date', 10);
        if ($date !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
            return 'Das Versionsdatum muss JJJJ-MM-TT sein.';
        }
        $out['version_date'] = $date;

        foreach (['tagline_de' => 240, 'tagline_en' => 240] as $key => $max) {
            $out[$key] = self::text($body, $existing, $key, $max) ?? '';
        }
        foreach (['status_de' => 400, 'status_en' => 400] as $key => $max) {
            $out[$key] = self::text($body, $existing, $key, $max);
        }

        $min = self::cents($body, $existing, 'donation_min', 200);
        $max = self::cents($body, $existing, 'donation_max', 50000);
        if ($min < DonationAmount::HARD_MIN || $max > DonationAmount::HARD_MAX || $min > $max) {
            return sprintf(
                'Die Spendengrenzen müssen zwischen %d und %d Cent liegen, Minimum unter Maximum.',
                DonationAmount::HARD_MIN,
                DonationAmount::HARD_MAX,
            );
        }
        $out['donation_min'] = $min;
        $out['donation_max'] = $max;

        $presetsRaw = $body['donation_presets'] ?? ($existing['donation_presets'] ?? '500,1000,2500');
        if (is_array($presetsRaw)) {
            $presetsRaw = implode(',', array_map('strval', $presetsRaw));
        }
        $presets = DonationAmount::presets((string) $presetsRaw, $min, $max);
        if ($presets === []) {
            return 'Mindestens ein Spendenbetrag muss innerhalb der Grenzen liegen.';
        }
        $out['donation_presets'] = implode(',', $presets);

        $currency = strtoupper(trim((string) ($body['currency'] ?? ($existing['currency'] ?? 'EUR'))));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return 'Die Währung muss ein ISO-Code wie EUR sein.';
        }
        $out['currency'] = $currency;

        $out['donations_enabled'] = self::flag($body, $existing, 'donations_enabled');
        $out['published'] = self::flag($body, $existing, 'published');

        return $out;
    }

    /** @return string|null|false false = present but not an https URL */
    private static function url(array $body, ?array $existing, string $key): string|null|false
    {
        $value = array_key_exists($key, $body) ? $body[$key] : ($existing[$key] ?? null);
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 300 || !str_starts_with($value, 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return $value;
    }

    private static function text(array $body, ?array $existing, string $key, int $max): ?string
    {
        $value = array_key_exists($key, $body) ? $body[$key] : ($existing[$key] ?? null);
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function cents(array $body, ?array $existing, string $key, int $default): int
    {
        $value = $body[$key] ?? ($existing[$key] ?? $default);
        return is_numeric($value) ? (int) $value : -1;
    }

    private static function flag(array $body, ?array $existing, string $key): int
    {
        $value = array_key_exists($key, $body) ? $body[$key] : ($existing[$key] ?? false);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
