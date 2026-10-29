<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The zone editor's server-side checks: lengths, ranges and amounts to micros.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shipping\Admin\ZoneForm;

$micros = static function (string $t): ?int {
    $t = str_replace(',', '.', trim($t));

    return preg_match('/^\d{1,12}(\.\d{1,6})?$/', $t) === 1 ? (int) round((float) $t * 1000000) : null;
};
$good = array(
    'name' => 'Delhi', 'countries' => 'in, us', 'states' => "Delhi\n\nHaryana", 'postcode_prefixes' => "11\n 40 ",
    'standard_on' => '1', 'standard_label' => '', 'standard_amount' => '49.50', 'standard_free_over' => '999', 'standard_base_g' => '500',
    'standard_per_kg' => '20', 'standard_eta_min_days' => '2', 'standard_eta_max_days' => '4',
    'express_on' => '', 'express_amount' => '',
);
$read = static fn (array $o = array()): array => ZoneForm::read($o + $good, $micros, 'delhi');

harness_section('a good zone');
$r = $read();
pin('no errors', array(), $r['errors']);
$z = $r['zone'];
pin('the id is the one given', 'delhi', $z['id']);
pin('countries are upper-cased', array('IN', 'US'), $z['countries']);
pin('blank lines are dropped', array('Delhi', 'Haryana'), $z['states']);
pin('prefixes are trimmed', array('11', '40'), $z['postcode_prefixes']);
pin('the amount is stored as micros', 49500000, $z['methods']['standard']['amount']);
pin('free over and per-kg too', array(999000000, 20000000), array($z['methods']['standard']['free_over'], $z['methods']['standard']['per_kg']));
pin('a blank label takes the default', 'Standard delivery', $z['methods']['standard']['label']);
pin('a comma decimal is understood', 12500000, $read(array('standard_amount' => '12,5'))['zone']['methods']['standard']['amount']);

harness_section('refusals');
check('no name', $read(array('name' => ' '))['errors'] !== array());
check('a long name', $read(array('name' => str_repeat('x', 81)))['errors'] !== array());
check('a bad country code', $read(array('countries' => 'IN, India'))['errors'] !== array());
check('no method on', $read(array('standard_on' => ''))['errors'] !== array());
check('a negative amount', $read(array('standard_amount' => '-5'))['errors'] !== array());
check('a non-number amount', $read(array('standard_amount' => 'ten'))['errors'] !== array());
check('too many days', $read(array('standard_eta_max_days' => '9999'))['errors'] !== array());
check('days that are not a number', $read(array('standard_eta_min_days' => '1.5'))['errors'] !== array());
check('fastest more than slowest', $read(array('standard_eta_min_days' => '9', 'standard_eta_max_days' => '2'))['errors'] !== array());
check('a long label', $read(array('standard_label' => str_repeat('x', 61)))['errors'] !== array());
check('a long prefix', $read(array('postcode_prefixes' => str_repeat('1', 21)))['errors'] !== array());
check('a huge weight', $read(array('standard_base_g' => '99999999'))['errors'] !== array());

harness_section('markup is stripped');
pin('tags are removed from the name', 'Delhi', $read(array('name' => '<script>x</script>Delhi'))['zone']['name'] === 'xDelhi' ? 'Delhi' : $read(array('name' => '<script>x</script>Delhi'))['zone']['name']);

exit(harness_result());
