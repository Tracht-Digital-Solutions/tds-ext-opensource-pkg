<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Opensource\Support\DonationAmount;
use Tds\Ext\Opensource\Support\DonationCheckout;
use Tds\Ext\Opensource\Support\DonationEvent;
use Tds\Ext\Opensource\Support\ProjectFields;

/** The pure rules: money, Stripe events, checkout parameters, panel input. */
final class SupportTest extends TestCase
{
    public function testPresetsAreSortedDeduplicatedAndInsideTheLimits(): void
    {
        self::assertSame([500, 1000, 2500], DonationAmount::presets('2500, 500,1000,500', 200, 50000));
        self::assertSame([500], DonationAmount::presets('50,500,abc,999999', 200, 50000));
        self::assertSame([], DonationAmount::presets('', 200, 50000));
    }

    public function testAmountAcceptsCentsOnly(): void
    {
        self::assertSame(1000, DonationAmount::parse(1000, 200, 50000));
        self::assertSame(1000, DonationAmount::parse('1000', 200, 50000));
        // A float could mean euros or cents. Refused, never guessed.
        self::assertSame('invalid_amount', DonationAmount::parse(10.5, 200, 50000));
        self::assertSame('invalid_amount', DonationAmount::parse('10,50', 200, 50000));
        self::assertSame('invalid_amount', DonationAmount::parse(null, 200, 50000));
        self::assertSame('amount_too_low', DonationAmount::parse(199, 200, 50000));
        self::assertSame('amount_too_high', DonationAmount::parse(50001, 200, 50000));
    }

    public function testHardLimitsWinOverAProjectsOwn(): void
    {
        self::assertSame([DonationAmount::HARD_MIN, DonationAmount::HARD_MAX], DonationAmount::limits(1, 10_000_000));
        self::assertSame('amount_too_high', DonationAmount::parse(DonationAmount::HARD_MAX + 1, 1, 10_000_000));
    }

    /** @return array<string,mixed> */
    private static function event(string $type, array $session): array
    {
        return ['type' => $type, 'data' => ['object' => $session + ['object' => 'checkout.session', 'id' => 'cs_1']]];
    }

    public function testOnlyOurSessionsAreRead(): void
    {
        $ours = ['metadata' => ['module' => 'opensource'], 'payment_status' => 'paid'];
        self::assertSame(['session' => 'cs_1', 'status' => 'paid'], DonationEvent::fromStripe(self::event('checkout.session.completed', $ours)));
        // The shared account delivers the shop's and the tools' sessions too.
        self::assertNull(DonationEvent::fromStripe(self::event('checkout.session.completed', ['metadata' => ['module' => 'tools'], 'payment_status' => 'paid'])));
        self::assertNull(DonationEvent::fromStripe(self::event('checkout.session.completed', ['payment_status' => 'paid'])));
    }

    public function testCompletedIsNotPaid(): void
    {
        $sepa = ['metadata' => ['module' => 'opensource'], 'payment_status' => 'unpaid'];
        self::assertNull(DonationEvent::fromStripe(self::event('checkout.session.completed', $sepa)));
        self::assertSame('paid', DonationEvent::fromStripe(self::event('checkout.session.async_payment_succeeded', $sepa))['status'] ?? null);
        self::assertSame('failed', DonationEvent::fromStripe(self::event('checkout.session.async_payment_failed', $sepa))['status'] ?? null);
        self::assertSame('expired', DonationEvent::fromStripe(self::event('checkout.session.expired', $sepa))['status'] ?? null);
        self::assertNull(DonationEvent::fromStripe(self::event('payment_intent.succeeded', $sepa)));
    }

    public function testCheckoutReturnsToTheSiteInTheVisitorsLanguage(): void
    {
        $project = ['slug' => 'wparted', 'name' => 'WParted', 'site_url' => 'https://wparted.tracht-digital.de/', 'currency' => 'EUR'];

        $de = DonationCheckout::params($project, 1000, 'de');
        self::assertSame('https://wparted.tracht-digital.de/spenden/danke', $de['success_url']);
        self::assertSame('https://wparted.tracht-digital.de/#spenden', $de['cancel_url']);
        self::assertSame('donate', $de['submit_type']);
        self::assertSame(1000, $de['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('eur', $de['line_items'][0]['price_data']['currency']);
        self::assertSame('Spende für WParted', $de['line_items'][0]['price_data']['product_data']['name']);
        self::assertSame(['module' => 'opensource', 'project' => 'wparted', 'lang' => 'de'], $de['metadata']);

        $en = DonationCheckout::params($project, 500, 'en');
        self::assertSame('https://wparted.tracht-digital.de/en/donate/thanks', $en['success_url']);
        self::assertSame('Donation to WParted', $en['line_items'][0]['price_data']['product_data']['name']);

        // An unknown language never becomes a path.
        self::assertSame($de['success_url'], DonationCheckout::params($project, 1000, '../evil')['success_url']);
    }

    /** @return array<string,mixed> */
    private static function body(array $override = []): array
    {
        return $override + [
            'slug' => 'wparted',
            'name' => 'WParted',
            'site_url' => 'https://wparted.tracht-digital.de',
            'repo_url' => 'https://git.tracht-digital.de/jtracht/WParted',
            'license' => 'GPL-2.0-or-later',
            'version' => '0.3.0-alpha',
            'version_date' => '2026-10-28',
            'donation_presets' => '500,1000,2500',
            'donations_enabled' => true,
            'published' => '1',
        ];
    }

    public function testAValidProjectIsStorable(): void
    {
        $fields = ProjectFields::validate(self::body(), null);
        self::assertIsArray($fields);
        self::assertSame('wparted', $fields['slug']);
        self::assertSame(1, $fields['donations_enabled']);
        self::assertSame(1, $fields['published']);
        self::assertSame('500,1000,2500', $fields['donation_presets']);
        self::assertSame('EUR', $fields['currency']);
    }

    public function testProjectRulesReject(): void
    {
        $cases = [
            'slug' => ['slug' => 'WParted!'],
            'http repo' => ['repo_url' => 'http://example.org/repo'],
            'site with path' => ['site_url' => 'https://example.org/wparted'],
            'license' => ['license' => 'GPL 2'],
            'version' => ['version' => 'latest', 'release_mode' => 'manual'],
            'date' => ['version_date' => '2026-02-30', 'release_mode' => 'manual'],
            'limits' => ['donation_min' => 5000, 'donation_max' => 1000],
            'presets outside' => ['donation_presets' => '50'],
            'currency' => ['currency' => 'euro'],
        ];
        foreach ($cases as $label => $override) {
            self::assertIsString(ProjectFields::validate(self::body($override), null), $label);
        }
    }

    public function testAnUpdateKeepsWhatItDoesNotSend(): void
    {
        $existing = ProjectFields::validate(self::body(['release_mode' => 'manual']), null);
        self::assertIsArray($existing);
        $update = ProjectFields::validate(['version' => '0.4.0-alpha'], $existing);
        self::assertIsArray($update);
        self::assertArrayNotHasKey('slug', $update, 'the slug is immutable');
        self::assertSame('0.4.0-alpha', $update['version']);
        self::assertSame('https://git.tracht-digital.de/jtracht/WParted', $update['repo_url']);
        self::assertSame(1, $update['donations_enabled']);
    }
}
