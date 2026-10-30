<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin against core's real cart pricer and a scratch database: a PIN in a zone gets the
 * zone's two methods with prices and dates in place of core's Standard, an address no zone
 * serves is refused, and the placed order's shipping line carries the method and its estimate.
 *
 * Skips (exit 0) when core has no cart pricer yet. Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/quote.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/shopclass', '/');
if (!is_file($core . '/oc-includes/osclass/classes/billing/CartPricer.php')) {
    echo "SKIP  core has no cart pricer (billing/CartPricer.php)\n";
    exit(getenv('REQUIRE_CORE') === '1' ? 1 : 0);
}
require_once $core . '/tests/lib/harness.php';
require_once $core . '/tests/lib/api-admin-kit.php';

use mindstellar\billing\CartPricer;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\exception\InvalidException;
use mindstellar\listing\VariantStore;
use mindstellar\shipping\Listener;
use mindstellar\shipping\Plugin;

$admin = api_admin_boot('osc_shipping_plugin');
$p     = DB_TABLE_PREFIX;

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\shipping\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'IN', 'India');
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$admin->query("UPDATE {$p}t_user SET s_phone_mobile = '5550100' WHERE pk_i_id = $tom");
$m = static fn (float $units): int => (int) round($units * 1000000);
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_shipping_flat' => (string) $m(80), 'date_format' => 'Y-m-d'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

final class ShippingFakeGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'fake';
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD'];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
PaymentGatewayRegistry::getInstance()->register(new ShippingFakeGateway());

$tee = seed_item($admin, $cat, $sue, 'Tee', 699.0);
$v   = VariantStore::saveDefault($tee, ['i_stock' => null, 'i_price' => $m(699), 'i_weight' => 1200]);

osc_add_filter('cart_shipping_methods', [Plugin::class, 'methods'], 10);
osc_add_filter('cart_free_over', [Listener::class, 'onFreeOver'], 10);
osc_add_filter('cart_problems', [Listener::class, 'onProblems'], 10);
osc_add_filter('billing_tracking_url', [Listener::class, 'onTrackingUrl'], 10);
Plugin::$clock = static fn (): int => strtotime('2026-10-18 12:00:00 UTC');

$method = static fn (int $amount, int $freeOver, int $min, int $max, bool $on = true): array => [
    'on' => $on, 'label' => '', 'amount' => $amount, 'free_over' => $freeOver, 'base_g' => 1000, 'per_kg' => $m(10), 'eta_min_days' => $min, 'eta_max_days' => $max,
];
Plugin::saveZones([
    [
        'id' => 'delhi', 'name' => 'Delhi', 'countries' => ['IN'], 'states' => [], 'postcode_prefixes' => ['11'],
        'methods' => ['standard' => $method($m(40), $m(5000), 2, 3), 'express' => ['label' => 'Express'] + $method($m(99), 0, 1, 1)],
    ],
    [
        'id' => 'india', 'name' => 'India', 'countries' => ['IN'], 'states' => [], 'postcode_prefixes' => [],
        'methods' => ['standard' => $method($m(60), 0, 4, 6), 'express' => $method(0, 0, 0, 0, false)],
    ],
]);

$pricer = new CartPricer();
$quote  = static fn (array $address, string $shipping = '') => $pricer->quote([['variant' => $v, 'qty' => 1]], ['address' => $address, 'shipping_method' => $shipping]);

harness_section('the quote');
$q = $quote(['country' => 'IN', 'postcode' => '110001']);
pin('a PIN starting 11 shows both methods', ['standard', 'express'], array_column($q->shippingMethods(), 'id'));
pin('core\'s flat Standard is gone: the zone prices it', [$m(50), $m(109)], array_column($q->shippingMethods(), 'amount'));
pin('with dates', ['Tue, 20 Oct – Wed, 21 Oct', 'Tomorrow'], array_column($q->shippingMethods(), 'eta'));
pin('the first method is chosen and priced', $m(50), $q->totals()['shipping']);
pin('Express can be chosen', $m(109), $quote(['country' => 'IN', 'postcode' => '110001'], 'express')->totals()['shipping']);
pin('no free-over meter: this method has no threshold the cart is under', ['amount' => $m(5000), 'remaining' => $m(5000 - 699)], $q->freeOver());
pin('no problem', [], $q->problems());

$q = $quote(['country' => 'IN', 'postcode' => '682001']);
pin('another PIN gets the country zone', ['standard'], array_column($q->shippingMethods(), 'id'));
pin('at its price', $m(60 + 10), $q->totals()['shipping']);
pin('a zone with no threshold shows no meter', null, $q->freeOver());

$q = $quote(['country' => 'US', 'postcode' => '73301']);
pin('an address no zone serves is a problem on the PIN', [['line' => null, 'code' => 'not_serviceable', 'pointer' => '/address/postcode', 'message' => 'We do not deliver to this PIN code yet.']], $q->problems());
pin('and core\'s Standard stays in the quote', [$m(80)], array_column($q->shippingMethods(), 'amount'));

$q = $quote([]);
pin('no address: core\'s Standard, no problem', [[$m(80)], []], [array_column($q->shippingMethods(), 'amount'), $q->problems()]);
osc_set_preference('default_zone', 'india', 'shipping');
scratchdb_forget_cache();
osc_reset_preferences();
$q = $quote([]);
pin('with a default zone the cart is priced before an address', [[$m(70)], []], [array_column($q->shippingMethods(), 'amount'), $q->problems()]);

harness_section('the placed order');
$checkout = new GoodsCheckout($pricer);
$address  = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Delhi', 'state' => 'Delhi', 'postcode' => '110001', 'country' => 'IN'];
$placed   = $checkout->place($tom, [['variant' => $v, 'qty' => 1]], $address, 'express', 'fake');
$order    = $placed['order'];
$lines    = OrderLineStore::ofOrder($order->getId());
$ship     = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'shipping'))[0] ?? [];
pin('the order total is the item plus Express', $m(699 + 109), \mindstellar\billing\OrderStore::find($order->getId())->getAmount());
pin('the shipping line is Express at its price', ['Express', $m(109)], [$ship['title'] ?? null, $ship['amount'] ?? null]);
pin('its meta carries the method and the estimate', ['id' => 'shipping.express', 'method' => 'express', 'eta' => 'Tomorrow'], $ship['meta'] ?? null);

$refused = static function (callable $fn): array {
    try {
        $fn();
    } catch (InvalidException $e) {
        return array_map(static fn (array $x): string => $x['pointer'] . ' ' . $x['code'], $e->errors());
    }

    return ['not refused'];
};
pin('an address no zone serves cannot be ordered', ['/address/postcode rejected'], $refused(static fn () => $checkout->place($tom, [['variant' => $v, 'qty' => 1]], ['postcode' => '73301', 'country' => 'US', 'state' => 'TX'] + $address, 'standard', 'fake')));

harness_section('tracking');
Plugin::saveZones([]);
osc_set_preference('carriers', 'Delhivery, https://www.delhivery.com/track/package/{tracking}', 'shipping');
$found = new \mindstellar\billing\event\TrackingUrlResolving('delhivery', 'AB1');
\mindstellar\boot\CoreServices::dispatcher()->dispatch($found);
pin('a tracking link from the carrier list', 'https://www.delhivery.com/track/package/AB1', $found->url());
osc_set_preference('carriers', "Delhivery, https://www.delhivery.com/track/package/{tracking}\nBlue Dart, https://bluedart.example.com/?n={tracking}", 'shipping');
osc_add_filter('admin_billing_carriers', [Listener::class, 'onCarriers'], 10);
$listed = \mindstellar\boot\CoreServices::dispatcher()->dispatch(new \mindstellar\billing\event\CarriersListing());
pin('the order screen suggests the set-up couriers', ['Delhivery', 'Blue Dart'], $listed->names());

exit(harness_result());
