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
 * Registration, settings access and the cart hooks. The hook methods are thin: they read the
 * settings and hand the cart to the pure classes.
 */
final class Plugin
{
    public const PAGE = 'shipping';

    private const NAMES = array('zones', 'default_zone', 'no_zone', 'carriers', 'weight_default_g');

    public const DEFAULT_WEIGHT_GRAMS = 500;

    /** @var array{0:?string,1:?Config} the last settings read, keyed by the raw values */
    private static array $memo = array(null, null);

    /** fn(): int -- replaces the clock in tests. */
    public static ?\Closure $clock = null;

    /**
     * Nothing to install: every setting has a default.
     */
    public static function install(): void
    {
    }

    /**
     * Remove the stored settings and zones.
     */
    public static function uninstall(): void
    {
        foreach (self::NAMES as $name) {
            osc_delete_preference($name, self::PAGE);
        }
    }

    /**
     * The settings, read from preferences (cached by core, so no query).
     */
    public static function config(): Config
    {
        $raw = array();
        foreach (self::NAMES as $name) {
            $raw[$name] = (string) osc_get_preference($name, self::PAGE);
        }
        $imperial = self::imperial();
        $key = serialize($raw) . ($imperial ? 'i' : 'm');
        if (self::$memo[0] === $key && self::$memo[1] !== null) {
            return self::$memo[1];
        }
        $weight = $raw['weight_default_g'] === '' ? self::DEFAULT_WEIGHT_GRAMS : (int) $raw['weight_default_g'];
        $config = new Config(
            Zones::parse($raw['zones']),
            trim($raw['default_zone']),
            $raw['no_zone'] === Config::STANDARD ? Config::STANDARD : Config::BLOCK,
            Carriers::parse($raw['carriers']),
            max(0, $weight),
            $imperial
        );
        self::$memo = array($key, $config);

        return $config;
    }

    /** Whether the shop weighs in ounces and pounds; a core without the unit setting is metric. */
    public static function imperial(): bool
    {
        return function_exists('osc_weight_unit') && osc_weight_unit() === 'imperial';
    }

    /** Grams as an admin field shows them: the small unit, grams or ounces. */
    public static function weightInput(int $grams, ?bool $imperial = null): string
    {
        if (!($imperial ?? self::imperial())) {
            return (string) $grams;
        }

        return rtrim(rtrim(number_format($grams / 28.349523125, 2, '.', ''), '0'), '.');
    }

    /** A weight typed in the small unit as whole grams (ounces are rounded), or null when it is not a number. */
    public static function weightParse(string $text, ?bool $imperial = null): ?int
    {
        $imperial ??= self::imperial();
        $text     = str_replace(',', '.', trim($text));
        if (preg_match($imperial ? '/^\d{1,6}(\.\d{1,2})?$/' : '/^\d{1,9}$/', $text) !== 1) {
            return null;
        }

        return (int) round((float) $text * ($imperial ? 28.349523125 : 1));
    }

    /**
     * What happens to an address no zone covers, in words, for the zones screen.
     */
    public static function noZoneNote(): string
    {
        return self::config()->noZone === Config::STANDARD
            ? __('An address no zone covers is charged the standard delivery rate from the billing settings.', 'shipping')
            : __('An address no zone covers cannot order: the buyer sees "We do not deliver to this address yet."', 'shipping');
    }

    /**
     * The zones as stored, for the admin screens.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function zones(): array
    {
        return Zones::parse((string) osc_get_preference('zones', self::PAGE));
    }

    /**
     * @param array<int,array<string,mixed>> $zones
     */
    public static function saveZones(array $zones): void
    {
        osc_set_preference('zones', json_encode(array_values($zones), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::PAGE);
        self::$memo = array(null, null);
    }

    /**
     * cart_shipping_methods: when a zone applies, its methods take the place of core's Standard.
     *
     * @param mixed               $methods
     * @param array<string,mixed> $cart
     *
     * @return mixed
     */
    public static function methods($methods, $cart = array())
    {
        if (!is_array($methods) || !is_array($cart)) {
            return $methods;
        }
        $config = self::config();
        $result = Resolver::resolve($config, $cart);
        if ($result['zone'] === null) {
            return $methods;
        }
        $methods = array_values(array_filter($methods, static fn ($m): bool => !is_array($m) || ($m['id'] ?? null) !== 'standard'));

        return array_merge($methods, Resolver::methods($result['zone'], $cart, $config, self::now(), self::dateFormatter()));
    }

    /**
     * cart_free_over: the matched zone's threshold replaces core's.
     *
     * @param mixed               $freeOver
     * @param array<string,mixed> $cart
     *
     * @return mixed
     */
    public static function freeOver($freeOver, $cart = array())
    {
        if (!is_array($cart)) {
            return $freeOver;
        }
        $amount = self::threshold($cart);
        if ($amount === null) {
            return $freeOver;
        }

        return $amount <= 0 ? null : array('amount' => $amount, 'remaining' => max(0, $amount - (int) ($cart['subtotal'] ?? 0)));
    }

    /**
     * The matched zone's free-delivery threshold in micros (0 for none), or null when no zone applies.
     *
     * @param array<string,mixed> $cart
     */
    public static function threshold(array $cart): ?int
    {
        $result = Resolver::resolve(self::config(), $cart);

        return $result['zone'] === null ? null : Resolver::freeOver($result['zone'], $cart);
    }

    /**
     * cart_problems: an address no zone serves.
     *
     * @param mixed               $problems
     * @param array<string,mixed> $cart
     *
     * @return mixed
     */
    public static function problems($problems, $cart = array())
    {
        if (!is_array($problems) || !is_array($cart)) {
            return $problems;
        }
        $blocked = self::blocked($cart);
        if ($blocked !== null) {
            $problems[] = $blocked;
        }

        return $problems;
    }

    /**
     * The problem for an address no zone serves, or null.
     *
     * @param array<string,mixed> $cart
     *
     * @return array{code:string,pointer:string,message:string}|null
     */
    public static function blocked(array $cart): ?array
    {
        $result = Resolver::resolve(self::config(), $cart);
        if (!$result['blocked']) {
            return null;
        }

        return array(
            'code'    => 'not_serviceable',
            'pointer' => $result['pointer'],
            'message' => $result['pointer'] === '/address/postcode'
                ? __('We do not deliver to this address yet.', 'shipping')
                : __('We do not deliver to this country yet.', 'shipping'),
        );
    }

    /**
     * billing_tracking_url: the carrier's link with the number in it.
     *
     * @param mixed $url
     * @param mixed $carrier
     * @param mixed $tracking
     *
     * @return mixed
     */
    public static function trackingUrl($url, $carrier = '', $tracking = '')
    {
        if (!is_string($carrier) || !is_string($tracking)) {
            return $url;
        }

        return Carriers::trackingUrl(self::config()->carriers, is_string($url) ? $url : '', $carrier, $tracking);
    }

    /**
     * The names of the set-up couriers, for the admin order screen's Carrier box.
     *
     * @return list<string>
     */
    public static function carrierNames(): array
    {
        return array_values(array_column(self::config()->carriers, 'name'));
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    private static function dateFormatter(): \Closure
    {
        return static function (int $timestamp): string {
            $date = date('Y-m-d', $timestamp);

            $text = function_exists('osc_format_date') ? trim((string) osc_format_date($date, 'D, j M')) : '';

            return $text !== '' ? $text : $date;
        };
    }
}
