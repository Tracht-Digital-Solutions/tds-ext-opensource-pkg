<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A salted hash of the client IP for the donate rate limit — never the raw IP.
 * Same rule as the contact form: the LAST `X-Forwarded-For` entry is the one our
 * own proxy appended; the first is whatever the client claims.
 */
final class ClientIp
{
    public static function hash(Request $req): ?string
    {
        $server = $req->getServerParams();
        $ip = '';
        $fwd = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($fwd !== '') {
            $parts = array_map('trim', explode(',', $fwd));
            $last = (string) end($parts);
            $ip = filter_var($last, FILTER_VALIDATE_IP) !== false ? $last : '';
        }
        if ($ip === '') {
            $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        }
        if ($ip === '') {
            return null;
        }
        $salt = (string) (getenv('SETTINGS_ENCRYPTION_KEY') ?: 'tds-opensource');
        return hash('sha256', $salt . '|' . $ip);
    }
}
