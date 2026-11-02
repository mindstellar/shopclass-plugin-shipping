<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The cart hooks end to end with stored preferences: which methods a cart gets, the free-over
 * meter, the not-serviceable problem, the delivery estimate on the line, tracking links.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shipping\Plugin;

$GLOBALS['prefs'] = array();
$GLOBALS['unit'] = 'metric';
function osc_weight_unit(): string
{
    return $GLOBALS['unit'];
}
function osc_get_preference($name, $section = '')
{
    return $GLOBALS['prefs'][$section . '/' . $name] ?? '';
}
function osc_set_preference($name, $value, $section = '')
{
    $GLOBALS['prefs'][$section . '/' . $name] = $value;
}
function osc_delete_preference($name, $section = '')
{
    unset($GLOBALS['prefs'][$section . '/' . $name]);
}

Plugin::$clock = static fn (): int => strtotime('2026-10-18 12:00:00 UTC');
$M = 1000000;

$store = static function (array $zones, array $more = array()): void {
    $GLOBALS['prefs'] = array();
    Plugin::saveZones($zones);
    foreach ($more as $k => $v) {
        osc_set_preference($k, $v, 'shipping');
    }
};
$cart = static fn (array $address, array $o = array()): array => $o + array(
    'address' => $address, 'needs_shipping' => true, 'subtotal' => 500 * $M, 'shipping_method' => null,
    'lines' => array(array('weight' => 1200, 'qty' => 1)),
);
$core = array(array('id' => 'standard', 'label' => 'Standard delivery', 'eta' => null, 'amount' => 80 * $M));
$ids  = static fn ($methods): array => array_column($methods, 'id');

$delhi = zone('delhi', array('countries' => array('IN'), 'postcode_prefixes' => array('11')), array(
    'standard' => array('amount' => 40 * $M, 'free_over' => 999 * $M, 'base_g' => 1000, 'per_kg' => 10 * $M, 'eta_min_days' => 2, 'eta_max_days' => 3),
    'express'  => array('on' => true, 'label' => 'Express', 'amount' => 99 * $M, 'eta_min_days' => 1, 'eta_max_days' => 1),
));
$india = zone('india', array('countries' => array('IN')));

harness_section('methods');
$store(array($delhi, $india));
$got = Plugin::methods($core, $cart(array('country' => 'IN', 'postcode' => '110001')));
pin('a PIN starting 11 shows both methods and drops core\'s Standard', array('standard', 'express'), $ids($got));
pin('standard: base plus one started kilo above 1000 g', 50 * $M, $got[0]['amount']);
pin('standard has a date range', '2026-10-20 – 2026-10-21', $got[0]['eta']);
pin('express is Tomorrow', array('Express', 'Tomorrow', 99 * $M), array($got[1]['label'], $got[1]['eta'], $got[1]['amount']));
pin('core\'s own amount is gone', array(50 * $M, 99 * $M), array_column($got, 'amount'));
$got = Plugin::methods($core, $cart(array('country' => 'IN', 'postcode' => '682001')));
pin('another PIN gets the country zone', array('standard'), $ids($got));
pin('and its price', 50 * $M, $got[0]['amount']);
$free = Plugin::methods($core, $cart(array('country' => 'IN', 'postcode' => '110001'), array('subtotal' => 999 * $M)));
pin('free over the zone threshold', 0, $free[0]['amount']);
$store(array(array('class_extra' => array('Bulky' => 200 * $M)) + $delhi));
$classCart = static fn (array $lines): array => $cart(array('country' => 'IN', 'postcode' => '110001'), array('lines' => $lines));
$got = Plugin::methods($core, $classCart(array(array('weight' => 1200, 'qty' => 2, 'shipping_class' => 'bulky'))));
pin('a saved zone keeps its class charges, and two bulky items add two charges', array(60 * $M + 400 * $M, 99 * $M + 400 * $M), array_column($got, 'amount'));
$got = Plugin::methods($core, $classCart(array(array('weight' => 5000, 'qty' => 1, 'shipping_class' => 'free'))));
pin('a cart of free-class items ships free by every method', array(0, 0), array_column($got, 'amount'));
$store(array($delhi, $india));
pin('a method of another plugin is kept', array('pickup', 'standard'), $ids(Plugin::methods(array(array('id' => 'pickup', 'label' => 'Pickup', 'eta' => null, 'amount' => 0)) + $core, $cart(array('country' => 'IN')))));
pin('no cart_needs_shipping: nothing changes', $core, Plugin::methods($core, $cart(array('country' => 'IN'), array('needs_shipping' => false))));
pin('a non-array input passes through', 'x', Plugin::methods('x', $cart(array())));

harness_section('no address: default_zone');
$store(array($delhi, $india), array('default_zone' => 'india'));
pin('the default zone prices the cart before an address', array('standard'), $ids(Plugin::methods($core, $cart(array()))));
pin('a postcode with no country is matched, not sent to the default zone', array('standard', 'express'), $ids(Plugin::methods($core, $cart(array('country' => '', 'postcode' => '110001')))));
$store(array($delhi, $india));
pin('no default zone: core\'s methods stay', $core, Plugin::methods($core, $cart(array())));
$store(array($delhi, $india), array('default_zone' => 'gone'));
pin('a default zone that was deleted is no zone', $core, Plugin::methods($core, $cart(array())));

harness_section('block and standard');
$store(array($delhi, $india));
pin('block is the default: the problem names the PIN', array(array('code' => 'not_serviceable', 'pointer' => '/address/postcode', 'message' => 'We do not deliver to this address yet.')), Plugin::problems(array(), $cart(array('country' => 'US', 'postcode' => '90210'))));
pin('and core\'s Standard is left in place', $core, Plugin::methods($core, $cart(array('country' => 'US', 'postcode' => '90210'))));
pin('a country with no postcode names the country', '/address/country', Plugin::problems(array(), $cart(array('country' => 'US')))[0]['pointer']);
$pins = zone('pins', array('countries' => array('IN'), 'postcode_prefixes' => array('1', '2', '3', '4', '5', '6', '7', '8')));
$kerala = zone('kerala', array('countries' => array('IN'), 'states' => array('Kerala')));
$store(array($delhi, $pins, $kerala), array('default_zone' => 'pins'));
pin('a served country with the PIN still to come is not refused', array(), Plugin::problems(array(), $cart(array('country' => 'IN'))));
pin('and the default zone prices it meanwhile', array('standard'), $ids(Plugin::methods($core, $cart(array('country' => 'IN')))));
pin('a state zone waits for the state too', array(), Plugin::problems(array(), $cart(array('country' => 'IN', 'postcode' => '990001'))));
pin('a PIN no zone serves is still refused once state and PIN are in', '/address/postcode', Plugin::problems(array(), $cart(array('country' => 'IN', 'state' => 'Goa', 'postcode' => '990001')))[0]['pointer'] ?? null);
pin('a state no zone lists, with the PIN to come, still waits for the PIN', array(), Plugin::problems(array(), $cart(array('country' => 'IN', 'state' => 'Goa'))));
pin('another country is refused at once', '/address/country', Plugin::problems(array(), $cart(array('country' => 'US')))[0]['pointer'] ?? null);
$store(array($delhi, $pins));
pin('no default zone: core\'s methods stay while it waits', $core, Plugin::methods($core, $cart(array('country' => 'IN'))));
$store(array($delhi, $india));
pin('a served address has no problem', array(), Plugin::problems(array(), $cart(array('country' => 'IN', 'postcode' => '682001'))));
pin('no address, no problem', array(), Plugin::problems(array(), $cart(array())));
pin('existing problems are kept', array(array('code' => 'qty')), array_slice(Plugin::problems(array(array('code' => 'qty')), $cart(array('country' => 'US'))), 0, 1));
pin('a cart that needs no shipping has no problem', array(), Plugin::problems(array(), $cart(array('country' => 'US'), array('needs_shipping' => false))));
$store(array($delhi, $india), array('no_zone' => 'standard'));
pin('standard: no problem', array(), Plugin::problems(array(), $cart(array('country' => 'US', 'postcode' => '90210'))));
pin('standard: core\'s method stays', $core, Plugin::methods($core, $cart(array('country' => 'US', 'postcode' => '90210'))));
$store(array());
pin('with no zones at all nothing is blocked', array(), Plugin::problems(array(), $cart(array('country' => 'US', 'postcode' => '90210'))));
pin('and nothing changes', $core, Plugin::methods($core, $cart(array('country' => 'IN'))));

harness_section('free-over meter');
$store(array($delhi, $india));
pin('the zone threshold replaces core\'s', array('amount' => 999 * $M, 'remaining' => 499 * $M), Plugin::freeOver(array('amount' => 5 * $M, 'remaining' => 1), $cart(array('country' => 'IN', 'postcode' => '110001'))));
pin('the chosen method\'s threshold is used', null, Plugin::freeOver(array('amount' => 5 * $M, 'remaining' => 1), $cart(array('country' => 'IN', 'postcode' => '110001'), array('shipping_method' => 'express'))));
pin('a zone with no threshold hides the meter', null, Plugin::freeOver(array('amount' => 5 * $M, 'remaining' => 1), $cart(array('country' => 'IN', 'postcode' => '682001'))));
pin('no zone: core\'s meter stays', array('amount' => 5 * $M, 'remaining' => 1), Plugin::freeOver(array('amount' => 5 * $M, 'remaining' => 1), $cart(array('country' => 'US', 'postcode' => '1'))));
pin('a met threshold has nothing remaining', 0, Plugin::freeOver(null, $cart(array('country' => 'IN', 'postcode' => '110001'), array('subtotal' => 2000 * $M)))['remaining']);

harness_section('tracking');
$store(array(), array('carriers' => "Delhivery, https://www.delhivery.com/track/package/{tracking}\nPlain, http://plain.example.com/{tracking}"));
pin('the carrier link', 'https://www.delhivery.com/track/package/AB1', Plugin::trackingUrl('', 'delhivery', 'AB1'));
pin('http is refused', '', Plugin::trackingUrl('', 'Plain', 'AB1'));
pin('an unknown carrier keeps the given url', 'https://x.example', Plugin::trackingUrl('https://x.example', 'Nobody', 'AB1'));
pin('the order screen is offered the good couriers by name', array('Delhivery'), Plugin::carrierNames());
$store(array());
pin('no couriers set up, no names', array(), Plugin::carrierNames());

harness_section('a bad stored value');
$GLOBALS['prefs'] = array('shipping/zones' => '{broken', 'shipping/carriers' => '[[[', 'shipping/no_zone' => 'weird', 'shipping/weight_default_g' => '-4');
pin('reads as no zones, standard-safe', $core, Plugin::methods($core, $cart(array('country' => 'IN'))));
pin('and no problem', array(), Plugin::problems(array(), $cart(array('country' => 'IN'))));
pin('weight default never goes below 0', 0, Plugin::config()->weightDefaultGrams);

harness_section('weight unit from core');
$store(array());
pin('a metric shop', false, Plugin::config()->imperial);
$GLOBALS['unit'] = 'imperial';
pin('an imperial shop', true, Plugin::config()->imperial);
$lbZone = zone('us', array('countries' => array('US')), array('standard' => array('amount' => 0, 'base_g' => 0, 'per_kg' => 10 * $M)));
$store(array($lbZone));
pin('a 1.1 lb cart is charged 2 lb', 20 * $M, Plugin::methods($core, $cart(array('country' => 'US'), array('lines' => array(array('weight' => 499, 'qty' => 1)))))[0]['amount'] ?? null);
$GLOBALS['unit'] = 'metric';
pin('the same cart in metric is charged 1 kg', 10 * $M, Plugin::methods($core, $cart(array('country' => 'US'), array('lines' => array(array('weight' => 499, 'qty' => 1)))))[0]['amount'] ?? null);

harness_section('the no-zone note');
$store(array($delhi), array());
check('the default note says the order is refused', str_contains(Plugin::noZoneNote(), 'cannot order'));
$store(array($delhi), array('no_zone' => 'standard'));
check('the standard setting says the standard rate is charged', str_contains(Plugin::noZoneNote(), 'standard delivery rate'));

harness_section('uninstall');
$store(array($delhi), array('default_zone' => 'delhi', 'carriers' => 'x'));
Plugin::uninstall();
pin('every preference is removed', array(), $GLOBALS['prefs']);

exit(harness_result());
