<?php

/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\currency\Money;
use mindstellar\shipping\Admin\Screens;
use mindstellar\shipping\Plugin;
use mindstellar\shipping\Zones;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Screens::allowed()) {
    osc_admin_empty(array('icon' => 'bi-shield-lock', 'title' => __('Only an administrator can manage shipping zones.', 'shipping')));

    return;
}

$id    = Params::getParamString('zone');
$known = $id === '' ? null : Zones::find(Plugin::zones(), $id);
$zone  = Screens::$retry['zone'] ?? $known;
if ($known === null) {
    $id = '';
}
$value = static function (string $key, $fallback = '') use ($zone) {
    return $zone[$key] ?? $fallback;
};
$method = static function (string $key) use ($zone): array {
    return $zone['methods'][$key] ?? array(
        'on' => $key === 'standard', 'label' => '', 'amount' => 0, 'free_over' => 0, 'base_g' => 0,
        'per_kg' => 0, 'eta_min_days' => 0, 'eta_max_days' => 0,
    );
};
$money = static fn (int $micros): string => $micros === 0 ? '' : Money::fromMicros($micros);
$currency = strtoupper((string) osc_currency());
$imperial = Plugin::imperial();

osc_admin_page_head($id === '' ? __('Add shipping zone', 'shipping') : __('Edit shipping zone', 'shipping'), array(
    array('label' => __('Back to zones', 'shipping'), 'url' => osc_route_admin_url(Screens::ROUTE), 'variant' => 'dim'),
));
osc_admin_form_open(array(
    'url'    => osc_route_admin_url(Screens::EDIT_ROUTE),
    'fields' => array('sh_do' => 'save', 'zone' => $id),
));
osc_admin_field(array('type' => 'text', 'name' => 'name', 'label' => __('Name', 'shipping'), 'value' => $value('name'), 'required' => true, 'attrs' => array('maxlength' => Zones::MAX_NAME)));
osc_admin_field(array(
    'type' => 'text', 'name' => 'countries', 'label' => __('Countries', 'shipping'),
    'value' => implode(', ', $value('countries', array())),
    'help' => __('Two-letter codes, separated by commas, such as IN, US. Leave empty for any country.', 'shipping'),
));
osc_admin_field(array(
    'type' => 'textarea', 'name' => 'states', 'label' => __('States', 'shipping'),
    'value' => implode("\n", $value('states', array())),
    'help' => __('One per line. Capitals and spaces do not matter. Leave empty for every state.', 'shipping'),
));
osc_admin_field(array(
    'type' => 'textarea', 'name' => 'postcode_prefixes', 'label' => __('Postcode starts', 'shipping'),
    'value' => implode("\n", $value('postcode_prefixes', array())),
    'help' => __('One per line, such as 11 for every postcode starting 11. Leave empty for every postcode.', 'shipping'),
));
$classLines = array();
foreach ($value('class_extra', array()) as $class => $micros) {
    $classLines[] = $class . ' = ' . Money::fromMicros((int) $micros);
}
osc_admin_field(array(
    'type' => 'textarea', 'name' => 'class_extra', 'label' => __('Shipping class charges', 'shipping'),
    'value' => implode("\n", $classLines),
    'help' => sprintf(__('One per line, such as bulky = 200. Added for each item of that class, on top of the price. Items in the class "free" ship free. Amounts in %s.', 'shipping'), $currency),
));
foreach (array('standard' => __('Standard delivery', 'shipping'), 'express' => __('Express delivery', 'shipping')) as $key => $heading) {
    $m = $method($key);
    osc_admin_form_section($heading);
    osc_admin_field(array('type' => 'checkbox', 'name' => $key . '_on', 'label' => __('Offer this method in the zone', 'shipping'), 'checked' => !empty($m['on']), 'value' => '1'));
    osc_admin_field(array('type' => 'text', 'name' => $key . '_label', 'label' => __('Name at checkout', 'shipping'), 'value' => (string) $m['label'], 'attrs' => array('maxlength' => Zones::MAX_LABEL, 'placeholder' => Zones::defaultLabel($key))));
    osc_admin_field(array('type' => 'text', 'name' => $key . '_amount', 'label' => __('Price', 'shipping'), 'value' => $money((int) $m['amount']), 'suffix' => $currency, 'width' => 'num'));
    osc_admin_field(array('type' => 'text', 'name' => $key . '_free_over', 'label' => __('Free over', 'shipping'), 'value' => $money((int) $m['free_over']), 'suffix' => $currency, 'width' => 'num', 'help' => __('Free when the items add up to this much. Leave empty for never.', 'shipping')));
    osc_admin_field(array('type' => 'number', 'name' => $key . '_base_g', 'label' => __('Weight included', 'shipping'), 'value' => Plugin::weightInput((int) $m['base_g'], $imperial), 'suffix' => $imperial ? __('oz', 'shipping') : __('grams', 'shipping'), 'width' => 'num', 'attrs' => array('min' => '0', 'step' => 'any')));
    osc_admin_field(array('type' => 'text', 'name' => $key . '_per_kg', 'label' => $imperial ? __('Price per extra lb', 'shipping') : __('Price per extra kg', 'shipping'), 'value' => $money((int) $m['per_kg']), 'suffix' => $currency, 'width' => 'num', 'help' => $imperial ? __('Added for each started pound above the weight included.', 'shipping') : __('Added for each started kilo above the weight included.', 'shipping')));
    osc_admin_field(array('type' => 'number', 'name' => $key . '_eta_min_days', 'label' => __('Fastest delivery', 'shipping'), 'value' => (int) $m['eta_min_days'], 'suffix' => __('days', 'shipping'), 'attrs' => array('min' => 0, 'max' => Zones::MAX_DAYS)));
    osc_admin_field(array('type' => 'number', 'name' => $key . '_eta_max_days', 'label' => __('Slowest delivery', 'shipping'), 'value' => (int) $m['eta_max_days'], 'suffix' => __('days', 'shipping'), 'attrs' => array('min' => 0, 'max' => Zones::MAX_DAYS), 'help' => __('Shown to the shopper as a date or a range of dates. Leave both at 0 to show none.', 'shipping')));
}
osc_admin_form_close(array(
    array('type' => 'submit', 'label' => __('Save zone', 'shipping')),
));
