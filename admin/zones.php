<?php

/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\currency\CurrencyService;
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

$zones   = Plugin::zones();
$listUrl = osc_route_admin_url(Screens::ROUTE);
$default = (string) osc_get_preference('default_zone', Plugin::PAGE);
$currency = strtoupper((string) osc_currency());
$money   = CurrencyService::make();
$price   = static fn (int $micros): string => $money->money($micros, $currency);

osc_admin_page_head(__('Shipping', 'shipping'));
?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <th><?php _e('Zone', 'shipping'); ?></th>
            <th><?php _e('Where', 'shipping'); ?></th>
            <th><?php _e('Methods', 'shipping'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($zones === array()) {
            osc_admin_table_empty(3, array(
                'icon'  => 'bi-truck',
                'title' => __('No zones yet', 'shipping'),
                'text'  => __('Until you add a zone, core\'s flat delivery rate applies everywhere.', 'shipping'),
            ));
        }
        foreach ($zones as $i => $zone) {
            $where = array();
            foreach (array('countries' => '', 'states' => '', 'postcode_prefixes' => '') as $key => $unused) {
                if ($zone[$key] !== array()) {
                    $where[] = implode(', ', array_slice($zone[$key], 0, 6)) . (count($zone[$key]) > 6 ? ' …' : '');
                }
            }
            $methods = array();
            foreach (Zones::METHODS as $key) {
                $m = $zone['methods'][$key];
                if ($m['on']) {
                    $methods[] = $m['label'] . ' ' . $price((int) $m['amount']);
                }
            } ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Zone', 'shipping')); ?>"><?php echo osc_esc_html($zone['name']); ?>
                    <?php if ($zone['id'] === $default) { ?><small class="text-muted"><?php echo osc_esc_html(__('(used before an address is known)', 'shipping')); ?></small><?php } ?>
                    <div class="actions"><ul>
                        <li><a href="<?php echo osc_esc_html(osc_route_admin_url(Screens::EDIT_ROUTE, array('zone' => $zone['id']))); ?>"><?php _e('Edit', 'shipping'); ?></a></li>
                        <li><a href="#" onclick="document.getElementById('sh-delete-<?php echo osc_esc_html($zone['id']); ?>').showModal(); return false;"><?php _e('Delete', 'shipping'); ?></a></li>
                        <?php if ($i > 0) { ?><li><a href="#" onclick="document.getElementById('sh-up-<?php echo osc_esc_html($zone['id']); ?>').submit(); return false;"><?php _e('Move up', 'shipping'); ?></a></li><?php } ?>
                        <?php if ($i < count($zones) - 1) { ?><li><a href="#" onclick="document.getElementById('sh-down-<?php echo osc_esc_html($zone['id']); ?>').submit(); return false;"><?php _e('Move down', 'shipping'); ?></a></li><?php } ?>
                    </ul></div></td>
                <td data-col-name="<?php echo osc_esc_html(__('Where', 'shipping')); ?>"><?php echo $where === array() ? osc_esc_html(__('Everywhere else', 'shipping')) : osc_esc_html(implode(' · ', $where)); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Methods', 'shipping')); ?>"><?php echo osc_esc_html(implode(' · ', $methods)); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<p class="text-muted"><?php echo osc_esc_html(Plugin::noZoneNote()); ?></p>
<?php
osc_admin_form_section(__('Settings', 'shipping'), array('spaced' => true));
osc_admin_settings_form(Plugin::PAGE, array('values' => osc_settings_values(Plugin::PAGE), 'titles' => false));
foreach ($zones as $zone) {
    foreach (array('up', 'down') as $dir) {
        osc_admin_form_open(array(
            'url'        => $listUrl,
            'id'         => 'sh-' . $dir . '-' . $zone['id'],
            'horizontal' => false,
            'fields'     => array('sh_do' => 'move', 'zone' => $zone['id'], 'dir' => $dir),
        ));
        osc_admin_form_close(null, array('horizontal' => false));
    }
    osc_admin_confirm_dialog(array(
        'id'      => 'sh-delete-' . $zone['id'],
        'url'     => $listUrl,
        'fields'  => array('sh_do' => 'delete', 'zone' => $zone['id']),
        'title'   => sprintf(__('Delete "%s"?', 'shipping'), $zone['name']),
        'text'    => __('Orders already placed keep what they were charged.', 'shipping'),
        'confirm' => __('Delete', 'shipping'),
    ));
}
