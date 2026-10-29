<?php

/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\shipping;

/**
 * What the plugin does for a cart: use a zone, leave core's delivery alone, or refuse the address.
 * Pure: it reads the cart array the cart hooks pass in.
 */
final class Resolver
{
    /**
     * @param array<string,mixed> $cart
     *
     * @return array{zone:?array<string,mixed>,blocked:bool,pointer:?string}
     */
    public static function resolve(Config $config, array $cart): array
    {
        $none = array('zone' => null, 'blocked' => false, 'pointer' => null);
        if (($cart['needs_shipping'] ?? true) === false) {
            return $none;
        }
        $address  = is_array($cart['address'] ?? null) ? $cart['address'] : array();
        $country  = self::part($address, 'country');
        $postcode = self::part($address, 'postcode');
        $state    = self::part($address, 'state');
        if ($country === '' && $postcode === '') {
            $zone = $config->defaultZone === '' ? null : Zones::find($config->zones, $config->defaultZone);

            return $zone !== null && Zones::serves($zone) ? array('zone' => $zone) + $none : $none;
        }
        $zone = Zones::match($config->zones, $country, $state, $postcode);
        if ($zone !== null) {
            return array('zone' => $zone) + $none;
        }
        // Not refused while the state or postcode a zone needs is still to come: the default zone prices the cart meanwhile.
        if (Zones::awaits($config->zones, $country, $state, $postcode)) {
            $zone = $config->defaultZone === '' ? null : Zones::find($config->zones, $config->defaultZone);

            return $zone !== null && Zones::serves($zone) ? array('zone' => $zone) + $none : $none;
        }
        $serving = array_filter($config->zones, static fn (array $z): bool => Zones::serves($z));
        if ($config->noZone !== Config::BLOCK || $serving === array()) {
            return $none;
        }

        return array('zone' => null, 'blocked' => true, 'pointer' => $postcode !== '' ? '/address/postcode' : '/address/country');
    }

    /**
     * The zone's methods priced for the cart, in order, as the cart_shipping_methods filter wants them.
     *
     * @param array<string,mixed> $zone
     * @param array<string,mixed> $cart
     * @param callable            $format fn(int $timestamp): string
     *
     * @return array<int,array{id:string,label:string,eta:?string,amount:int}>
     */
    public static function methods(array $zone, array $cart, Config $config, int $now, callable $format): array
    {
        $grams    = Rates::grams(is_array($cart['lines'] ?? null) ? $cart['lines'] : array(), $config->weightDefaultGrams);
        $subtotal = (int) ($cart['subtotal'] ?? 0);
        $out      = array();
        foreach (Zones::METHODS as $key) {
            $method = $zone['methods'][$key];
            if (!$method['on']) {
                continue;
            }
            $out[] = array(
                'id'     => $key,
                'label'  => (string) $method['label'],
                'eta'    => Rates::eta($method, $now, $format),
                'amount' => Rates::amount($method, $grams, $subtotal),
            );
        }

        return $out;
    }

    /**
     * The free-delivery threshold of the method the shopper has chosen (the first one on when none is).
     *
     * @param array<string,mixed> $zone
     * @param array<string,mixed> $cart
     */
    public static function freeOver(array $zone, array $cart): int
    {
        $asked = (string) ($cart['shipping_method'] ?? '');
        $first = null;
        foreach (Zones::METHODS as $key) {
            if (!$zone['methods'][$key]['on']) {
                continue;
            }
            $first = $first ?? $key;
            if ($key === $asked) {
                return (int) $zone['methods'][$key]['free_over'];
            }
        }

        return $first === null ? 0 : (int) $zone['methods'][$first]['free_over'];
    }

    /**
     * @param array<string,mixed> $address
     */
    private static function part(array $address, string $key): string
    {
        return is_string($address[$key] ?? null) ? trim($address[$key]) : '';
    }
}
