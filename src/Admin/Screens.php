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

namespace mindstellar\shipping\Admin;

use mindstellar\currency\Money;
use mindstellar\shipping\Plugin;
use mindstellar\shipping\Zones;
use Params;

/**
 * The Zones screens: the menu entry, the page headers and the posts. Only an administrator
 * reaches them, and every post checks the CSRF token first.
 */
final class Screens
{
    public const ROUTE = 'shipping-zones';

    public const EDIT_ROUTE = 'shipping-zone';

    /** @var array{zone:array<string,mixed>,errors:string[]}|null a refused save, for the editor to show again */
    public static ?array $retry = null;

    /** The one entry, Billing > Shipping: the admin_menu_init hook. The zone editor and the old settings URL light it. */
    public static function menu(): void
    {
        osc_add_admin_submenu_page(
            'billing',
            __('Shipping', 'shipping'),
            osc_route_admin_url(self::ROUTE),
            'shipping-zones',
            'administrator',
            null,
            array(osc_route_admin_url(self::EDIT_ROUTE), osc_settings_page_url(Plugin::PAGE)),
            10
        );
    }

    /** The header core draws around each screen: the init_admin hook. */
    public static function pages(): void
    {
        osc_admin_plugin_page(self::ROUTE, array(
            'title'   => __('Shipping', 'shipping'),
            'help'    => static function () {
                echo '<p>' . osc_esc_html(__('A zone is the places you deliver to and what delivery costs there. The most exact zone wins: a postcode start beats a state, and a state beats a country. Among equals, the one higher in the list wins.', 'shipping')) . '</p>';
            },
            'actions' => array(
                array('icon' => 'bi-plus-circle-fill', 'url' => osc_route_admin_url(self::EDIT_ROUTE), 'title' => __('Add zone', 'shipping')),
            ),
        ));
        osc_admin_plugin_page(self::EDIT_ROUTE, array('title' => __('Shipping zone', 'shipping')));
    }

    /** Whether the signed-in user may manage zones. */
    public static function allowed(): bool
    {
        return osc_is_admin_user_logged_in() && !osc_is_moderator();
    }

    /** Act on a post to one of the screens: the init_admin hook. */
    public static function handlePost(): void
    {
        $route = Params::getParamString('route');
        if (!in_array($route, array(self::ROUTE, self::EDIT_ROUTE), true)
            || Params::getServerParam('REQUEST_METHOD') !== 'POST'
            || Params::getParamString('sh_do') === ''
        ) {
            return;
        }
        osc_csrf_check();
        if (!self::allowed()) {
            osc_add_flash_error_message(__('Only an administrator can manage shipping zones.', 'shipping'), 'admin');
            osc_redirect_to(osc_admin_base_url(true));
        }
        $id = Params::getParamString('zone');
        switch (Params::getParamString('sh_do')) {
            case 'save':
                self::save($id);

                return;
            case 'delete':
                self::delete($id);
                break;
            case 'move':
                self::move($id, Params::getParamString('dir') === 'up' ? -1 : 1);
                break;
        }
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /** A typed amount in the site currency as micros, or null when it is not a number. */
    public static function amount(string $text): ?int
    {
        $text = str_replace(',', '.', trim($text));

        return preg_match('/^\d{1,12}(\.\d{1,6})?$/', $text) === 1 ? Money::toMicros($text) : null;
    }

    private static function save(string $id): void
    {
        $zones = Plugin::zones();
        $known = $id !== '' ? Zones::find($zones, $id) : null;
        if ($known === null && count($zones) >= Zones::MAX_ZONES) {
            osc_add_flash_error_message(sprintf(__('At most %d zones.', 'shipping'), Zones::MAX_ZONES), 'admin');
            osc_redirect_to(osc_route_admin_url(self::ROUTE));
        }
        $post = array();
        foreach (array('name', 'countries', 'states', 'postcode_prefixes', 'class_extra') as $field) {
            $post[$field] = Params::getParamString($field);
        }
        foreach (Zones::METHODS as $key) {
            foreach (array('on', 'label', 'amount', 'free_over', 'base_g', 'per_kg', 'eta_min_days', 'eta_max_days') as $field) {
                $post[$key . '_' . $field] = Params::getParamString($key . '_' . $field);
            }
        }
        $zoneId = $known !== null ? $id : self::newId(Params::getParamString('name'), $zones);
        $result = ZoneForm::read($post, array(self::class, 'amount'), $zoneId);
        if ($result['errors'] !== array() || $result['zone'] === array()) {
            foreach ($result['errors'] as $error) {
                osc_add_flash_error_message($error, 'admin');
            }
            // No redirect: the editor draws this request's values again.
            self::$retry = $result;

            return;
        }
        $out   = array();
        $found = false;
        foreach ($zones as $zone) {
            if ($zone['id'] === $zoneId) {
                $zone  = $result['zone'];
                $found = true;
            }
            $out[] = $zone;
        }
        if (!$found) {
            $out[] = $result['zone'];
        }
        Plugin::saveZones($out);
        osc_add_flash_ok_message(__('The zone is saved.', 'shipping'), 'admin');
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    private static function delete(string $id): void
    {
        $zones = Plugin::zones();
        $out   = array_values(array_filter($zones, static fn (array $z): bool => $z['id'] !== $id));
        if (count($out) === count($zones)) {
            osc_add_flash_error_message(__('That zone no longer exists.', 'shipping'), 'admin');

            return;
        }
        Plugin::saveZones($out);
        if ((string) osc_get_preference('default_zone', Plugin::PAGE) === $id) {
            osc_set_preference('default_zone', '', Plugin::PAGE);
        }
        osc_add_flash_ok_message(__('The zone is deleted.', 'shipping'), 'admin');
    }

    private static function move(string $id, int $by): void
    {
        $zones = Plugin::zones();
        foreach ($zones as $i => $zone) {
            $j = $i + $by;
            if ($zone['id'] === $id && isset($zones[$j])) {
                $zones[$i] = $zones[$j];
                $zones[$j] = $zone;
                Plugin::saveZones($zones);
                break;
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $zones
     */
    private static function newId(string $name, array $zones): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'zone' : substr($base, 0, 24);
        $id   = $base;
        for ($n = 2; Zones::find($zones, $id) !== null; $n++) {
            $id = $base . '-' . $n;
        }

        return $id;
    }
}
