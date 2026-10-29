<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Method prices, free-over, cart weight and the delivery estimate.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shipping\Rates;

$M = 1000000;
$m = static fn (array $o = array()): array => $o + array('on' => true, 'label' => 'S', 'amount' => 50 * $M, 'free_over' => 0, 'base_g' => 0, 'per_kg' => 0, 'eta_min_days' => 0, 'eta_max_days' => 0);

harness_section('price');
pin('a flat rate', 50 * $M, Rates::amount($m(), 9000, 100 * $M));
$w = $m(array('base_g' => 500, 'per_kg' => 20 * $M));
pin('inside the base weight, only the base amount', 50 * $M, Rates::amount($w, 500, 0));
pin('a started kilo is charged whole', 70 * $M, Rates::amount($w, 501, 0));
pin('exactly one kilo over', 70 * $M, Rates::amount($w, 1500, 0));
pin('one gram past a full kilo', 90 * $M, Rates::amount($w, 1501, 0));
pin('a zero base weight charges from the first gram', 70 * $M, Rates::amount($m(array('per_kg' => 20 * $M)), 1, 0));
pin('an empty cart weighs nothing', 50 * $M, Rates::amount($w, 0, 0));
pin('a parcel far under the base weight costs the base amount, never less', 50 * $M, Rates::amount($m(array('base_g' => 5000, 'per_kg' => 20 * $M)), 1000, 0));

harness_section('free over');
$f = $m(array('free_over' => 999 * $M, 'per_kg' => 20 * $M));
pin('below the threshold', 90 * $M, Rates::amount($f, 1500, 998 * $M));
pin('at the threshold it is free', 0, Rates::amount($f, 1500, 999 * $M));
pin('over it, free whatever the weight', 0, Rates::amount($f, 90000, 5000 * $M));
pin('zero means never', 50 * $M, Rates::amount($m(), 1000, 10 ** 12));

harness_section('weight of the cart');
$lines = array(array('weight' => 250, 'qty' => 2), array('weight' => null, 'qty' => 1), array('weight' => 0, 'qty' => 2), array('qty' => 1));
pin('a line with no weight counts as the default', 500 + 500 + 1000 + 500, Rates::grams($lines, 500));
pin('a default of 0 counts nothing', 500, Rates::grams($lines, 0));
pin('no lines', 0, Rates::grams(array(), 500));

harness_section('delivery estimate');
$now = strtotime('2026-10-18 12:00:00 UTC');
$fmt = static fn (int $ts): string => date('j M', $ts);
pin('no estimate', null, Rates::eta($m(), $now, $fmt));
pin('exactly one day is Tomorrow', 'Tomorrow', Rates::eta($m(array('eta_min_days' => 1, 'eta_max_days' => 1)), $now, $fmt));
pin('two days is a date', '20 Oct', Rates::eta($m(array('eta_min_days' => 2, 'eta_max_days' => 2)), $now, $fmt));
pin('a range of dates', '21 Oct – 23 Oct', Rates::eta($m(array('eta_min_days' => 3, 'eta_max_days' => 5)), $now, $fmt));
pin('one to two days', '19 Oct – 20 Oct', Rates::eta($m(array('eta_min_days' => 1, 'eta_max_days' => 2)), $now, $fmt));
pin('a month end', '1 Nov – 3 Nov', Rates::eta($m(array('eta_min_days' => 14, 'eta_max_days' => 16)), $now, $fmt));

exit(harness_result());
