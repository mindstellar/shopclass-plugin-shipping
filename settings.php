<?php

/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\shipping\Admin\Screens;
use mindstellar\shipping\Carriers;
use mindstellar\shipping\Plugin;
use mindstellar\shipping\Zones;

/*
 * The settings page declaration. The Shipping screen draws the form under the zones list; core renders it, checks CSRF and the admin's capability,
 * validates each field and stores it under the "shipping" section. The zones themselves are
 * edited on their own screen.
 */

$zoneOptions = array('' => __('None', 'shipping'));
foreach (Plugin::zones() as $zone) {
    $zoneOptions[$zone['id']] = $zone['name'];
}

return array(
    'title'  => __('Shipping', 'shipping'),
    'menu'   => '',
    'return_url' => static fn (): string => osc_route_admin_url(Screens::ROUTE),
    'intro'  => __('Where you deliver, what it costs and how buyers track a parcel.', 'shipping'),
    'fields' => array(
        array(
            'type'    => 'select',
            'name'    => 'default_zone',
            'label'   => __('Zone used before an address is known', 'shipping'),
            'help'    => __('Sets the delivery price and date shown in the cart before the shopper gives a postcode.', 'shipping'),
            'options' => $zoneOptions,
            'default' => '',
        ),
        array(
            'type'    => 'radio',
            'name'    => 'no_zone',
            'label'   => __('When no zone covers the address', 'shipping'),
            'help'    => __('Zones decide the charge for every address they cover. This only applies to the rest.', 'shipping'),
            'options' => array(
                'block'    => __('Refuse the order: "We do not deliver to this address yet."', 'shipping'),
                'standard' => __('Charge the standard delivery rate from the billing settings', 'shipping'),
            ),
            'default' => 'block',
        ),
        array(
            'type'     => 'number',
            'name'     => 'weight_default_g',
            'label'    => __('Weight of an item with none set', 'shipping'),
            'suffix'   => __('grams', 'shipping'),
            'default'  => Plugin::DEFAULT_WEIGHT_GRAMS,
            'attrs'    => array('min' => 0, 'max' => Zones::MAX_GRAMS),
            'validate' => static function ($value) {
                return is_numeric($value) && (int) $value >= 0 && (int) $value <= Zones::MAX_GRAMS ? null : __('Enter a weight in grams.', 'shipping');
            },
        ),
        array(
            'type'     => 'textarea',
            'name'     => 'carriers',
            'label'    => __('Couriers and tracking links', 'shipping'),
            'help'     => __('One per line: the courier\'s name, a comma, then its tracking link with {tracking} where the number goes. Links must start with https://.', 'shipping'),
            'attrs'    => array('rows' => 5, 'placeholder' => 'My Courier, https://example.com/track/{tracking}'),
            'purify'   => false,
            'sanitize' => static fn ($value) => trim(str_replace("\r", '', (string) $value)),
            'validate' => static function ($value) {
                return Carriers::error((string) $value);
            },
        ),
    ),
);
