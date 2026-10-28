<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

/**
 * The Stripe Checkout Session for one donation, as plain parameters.
 *
 * Kept pure so the parameters are tested without Stripe. The price is inline
 * (`price_data`), so a project needs no product set up in Stripe.
 * `submit_type=donate` makes Checkout say "Spenden" instead of "Bezahlen".
 *
 * Return paths are the site's, by language. They are fixed here because the
 * site's routes are fixed (`/spenden/danke`, `/en/donate/thanks`); a free URL
 * from the request would make this route an open redirect behind Stripe's logo.
 */
final class DonationCheckout
{
    public const PATHS = [
        'de' => ['success' => '/spenden/danke', 'cancel' => '/#spenden'],
        'en' => ['success' => '/en/donate/thanks', 'cancel' => '/en/#donate'],
    ];

    /**
     * @param array{slug:string,name:string,site_url:?string,currency:string} $project
     * @return array<string,mixed>
     */
    public static function params(array $project, int $amount, string $lang): array
    {
        $lang = isset(self::PATHS[$lang]) ? $lang : 'de';
        $origin = rtrim((string) ($project['site_url'] ?? ''), '/');
        $name = $lang === 'en'
            ? 'Donation to ' . $project['name']
            : 'Spende für ' . $project['name'];
        $meta = ['module' => DonationEvent::MODULE, 'project' => $project['slug'], 'lang' => $lang];

        return [
            'mode' => 'payment',
            'submit_type' => 'donate',
            'locale' => $lang,
            'success_url' => $origin . self::PATHS[$lang]['success'],
            'cancel_url' => $origin . self::PATHS[$lang]['cancel'],
            'metadata' => $meta,
            'payment_intent_data' => [
                'description' => $name,
                'metadata' => $meta,
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($project['currency']),
                    'unit_amount' => $amount,
                    'product_data' => ['name' => $name],
                ],
            ]],
        ];
    }
}
