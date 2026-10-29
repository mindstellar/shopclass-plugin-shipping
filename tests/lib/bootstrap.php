<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes with no core, no database and no network.
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('UTC');
ini_set('display_errors', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\shipping\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

if (!function_exists('__')) {
    function __($text, $domain = '')
    {
        return $text;
    }
}

use mindstellar\shipping\Config;
use mindstellar\shipping\Zones;

/** A zone in the stored shape; $methods overrides per method key. */
function zone(string $id, array $where = array(), array $methods = array()): array
{
    $standard = array('on' => true, 'label' => 'Standard delivery', 'amount' => 50000000, 'free_over' => 0, 'base_g' => 0, 'per_kg' => 0, 'eta_min_days' => 3, 'eta_max_days' => 5);
    $express  = array('on' => false) + $standard;

    return Zones::clean(array('id' => $id, 'name' => ucfirst($id)) + $where + array(
        'methods' => array(
            'standard' => ($methods['standard'] ?? array()) + $standard,
            'express'  => ($methods['express'] ?? array()) + $express,
        ),
    ));
}

function config(array $zones, array $extra = array()): Config
{
    $c = new Config($zones);
    foreach ($extra as $k => $v) {
        $c->$k = $v;
    }

    return $c;
}
