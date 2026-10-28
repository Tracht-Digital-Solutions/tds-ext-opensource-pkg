<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

/**
 * Reads a Stripe webhook event into the one change it means for `oss_donation`.
 *
 * The Stripe account is shared by every module, so an endpoint subscribed to
 * `checkout.session.completed` also receives the shop's and the tools'. Only a
 * session carrying `metadata.module = opensource` is ours; everything else is
 * acknowledged and ignored.
 *
 * `completed` is not `paid`: a delayed method (SEPA) completes `unpaid` and
 * confirms later with `async_payment_succeeded` — or fails with
 * `async_payment_failed`.
 */
final class DonationEvent
{
    public const MODULE = 'opensource';

    /**
     * @param array<string,mixed> $event
     * @return array{session:string,status:string}|null
     */
    public static function fromStripe(array $event): ?array
    {
        $type = (string) ($event['type'] ?? '');
        $session = $event['data']['object'] ?? null;
        if (!is_array($session) || ($session['object'] ?? 'checkout.session') !== 'checkout.session') {
            return null;
        }
        if ((string) ($session['metadata']['module'] ?? '') !== self::MODULE) {
            return null;
        }
        $id = (string) ($session['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $status = match ($type) {
            'checkout.session.completed' => ($session['payment_status'] ?? null) === 'paid' ? 'paid' : null,
            'checkout.session.async_payment_succeeded' => 'paid',
            'checkout.session.async_payment_failed' => 'failed',
            'checkout.session.expired' => 'expired',
            default => null,
        };
        return $status === null ? null : ['session' => $id, 'status' => $status];
    }
}
