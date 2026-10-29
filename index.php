<?php
/*
Plugin Name: Shipping
Plugin URI: https://github.com/mindstellar/shopclass-plugin-shipping
Description: Delivery zones with flat and weight-based rates, Standard and Express delivery, free delivery over an amount, delivery dates, PIN code checks and courier tracking links for Shopclass shop orders.
Version: 0.1.1
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: shipping
Requires Shopclass: 7.1.0
Tested up to: 7.1
Requires PHP: 8.2
Support URI: https://github.com/mindstellar/shopclass-plugin-shipping/issues
Modes: shop
*/

/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\shipping\Admin\Screens;
use mindstellar\shipping\Listener;
use mindstellar\shipping\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\shipping\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// The plugin works through Shopclass 7.1's cart events, its settings pages and its admin screens.
// On a core without them it loads and does nothing but say so.
if (!class_exists('mindstellar\\billing\\CartPricer')
    || !defined('mindstellar\\billing\\CartPricer::PROBLEM_POINTERS')
    || !class_exists('mindstellar\\billing\\event\\CartProblems')
    || !function_exists('osc_register_settings_page')
    || !function_exists('osc_admin_plugin_page')
) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Shipping needs Shopclass 7.1 or later with shop mode and is switched off. Update Shopclass to use it.', 'shipping'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

// After core's Standard delivery (priority 5), so a zone can take its place.
osc_add_filter('cart_shipping_methods', array(Plugin::class, 'methods'), 10);
osc_add_filter('cart_free_over', array(Listener::class, 'onFreeOver'), 10);
osc_add_filter('cart_problems', array(Listener::class, 'onProblems'), 10);
osc_add_filter('billing_tracking_url', array(Listener::class, 'onTrackingUrl'), 10);

// The Zones screens.
foreach (array(Screens::ROUTE => 'admin/zones.php', Screens::EDIT_ROUTE => 'admin/zone.php') as $shRoute => $shFile) {
    osc_add_route($shRoute, str_replace('-', '/', $shRoute), str_replace('-', '/', $shRoute), osc_plugin_folder(__FILE__) . $shFile);
}
osc_add_hook('init_admin', array(Screens::class, 'handlePost'));
osc_add_hook('admin_menu_init', array(Screens::class, 'menu'));
osc_add_hook('init_admin', array(Screens::class, 'pages'));
