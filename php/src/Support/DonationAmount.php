<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

/**
 * The money rules of a donation, in one place. Cents throughout.
 *
 * The public site offers presets and a free amount. Both arrive here as one
 * integer, checked against the project's minimum and maximum — the site's own
 * check is a convenience, this one is the rule.
 */
final class DonationAmount
{
    /** Stripe's own floor for EUR card payments is 50 cents; ours is higher. */
    public const HARD_MIN = 100;

    /** A ceiling no project can raise: a typo must not become a 5-figure charge. */
    public const HARD_MAX = 100000;

    /** At most this many presets are offered. */
    public const MAX_PRESETS = 6;

    /**
     * `"500, 1000,2500"` → `[500, 1000, 2500]`: deduplicated, sorted, inside the
     * project's limits. Anything unparsable is dropped, never an error — the
     * panel validates on save, this reads what is stored.
     *
     * @return list<int>
     */
    public static function presets(string $csv, int $min, int $max): array
    {
        $out = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/^\d{1,7}$/', $part) !== 1) {
                continue;
            }
            $cents = (int) $part;
            if ($cents >= $min && $cents <= $max) {
                $out[$cents] = $cents;
            }
        }
        $out = array_values($out);
        sort($out);
        return array_slice($out, 0, self::MAX_PRESETS);
    }

    /**
     * The amount a visitor asked for, or an error code.
     *
     * Accepts an int or a digit string of cents. Floats are refused: `10.5`
     * could mean euros or cents, and guessing is how a donor pays 100 times
     * what they meant.
     */
    public static function parse(mixed $value, int $min, int $max): int|string
    {
        if (is_string($value) && preg_match('/^\d{1,7}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value)) {
            return 'invalid_amount';
        }
        [$min, $max] = self::limits($min, $max);
        if ($value < $min) {
            return 'amount_too_low';
        }
        if ($value > $max) {
            return 'amount_too_high';
        }
        return $value;
    }

    /**
     * The project's limits clamped to the hard ones.
     *
     * @return array{0:int,1:int}
     */
    public static function limits(int $min, int $max): array
    {
        $min = max(self::HARD_MIN, $min);
        $max = min(self::HARD_MAX, $max);
        return [$min, max($min, $max)];
    }
}
