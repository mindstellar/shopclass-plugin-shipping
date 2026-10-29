<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Zone matching by country, state and PIN prefix, and reading a stored zone list.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shipping\Zones;

$id = static fn (?array $z): ?string => $z['id'] ?? null;

harness_section('matching');
$zones = array(
    zone('india', array('countries' => array('IN'))),
    zone('delhi', array('countries' => array('IN'), 'states' => array('Delhi'))),
    zone('metro', array('countries' => array('IN'), 'postcode_prefixes' => array('11', '40'))),
    zone('world'),
);
pin('country only', 'india', $id(Zones::match($zones, 'IN', 'Kerala', '682001')));
pin('a state beats a country', 'delhi', $id(Zones::match($zones, 'IN', 'delhi', '682001')));
pin('state ignores case and spaces', 'delhi', $id(Zones::match($zones, 'in', ' DEL hi ', '682001')));
pin('a prefix beats a state', 'metro', $id(Zones::match($zones, 'IN', 'Delhi', '110001')));
pin('a prefix with spaces in the postcode', 'metro', $id(Zones::match($zones, 'IN', '', '11 00 01')));
pin('a prefix must start the postcode, not sit inside it', 'india', $id(Zones::match($zones, 'IN', '', '211001')));
pin('another country falls to the open zone', 'world', $id(Zones::match($zones, 'US', 'Texas', '73301')));
pin('no country given: the country test passes', 'india', $id(Zones::match(array_slice($zones, 0, 1), '', '', '682001')));
pin('a prefix zone needs a postcode', 'india', $id(Zones::match($zones, 'IN', '', '')));
pin('a state zone needs a state', 'india', $id(Zones::match($zones, 'IN', '', '682001')));

$first = array(zone('a', array('countries' => array('IN'))), zone('b', array('countries' => array('IN'))));
pin('first match wins among equals', 'a', $id(Zones::match($first, 'IN', '', '')));
pin('and list order is what decides', 'b', $id(Zones::match(array_reverse($first), 'IN', '', '')));
pin('no zone at all', null, $id(Zones::match(array(zone('only', array('countries' => array('IN')))), 'US', '', '')));

$off = zone('off', array('countries' => array('IN')), array('standard' => array('on' => false)));
pin('a zone with every method off never matches', null, $id(Zones::match(array($off), 'IN', '', '')));
pin('the next zone is used instead', 'world', $id(Zones::match(array($off, zone('world')), 'IN', '', '')));

harness_section('reading what is stored');
pin('malformed JSON reads as no zones', array(), Zones::parse('{not json'));
pin('empty reads as no zones', array(), Zones::parse(''));
pin('not a list reads as no zones', array(), Zones::parse('"x"'));
$stored = json_encode(array(
    'junk',
    array('id' => 'BAD ID', 'name' => 'x'),
    array('id' => 'ok', 'name' => '<b>Ok</b>', 'countries' => array('in', 'india', 5), 'methods' => array('standard' => array('on' => true, 'amount' => -5, 'eta_min_days' => 9, 'eta_max_days' => 2))),
    array('id' => 'ok', 'name' => 'duplicate'),
));
$read = Zones::parse($stored);
pin('only the usable zone is kept', array('ok'), array_column($read, 'id'));
pin('tags go from the name', 'Ok', $read[0]['name']);
pin('countries are cleaned', array('IN'), $read[0]['countries']);
pin('a negative amount reads as 0', 0, $read[0]['methods']['standard']['amount']);
pin('min and max days are put in order', array(2, 9), array($read[0]['methods']['standard']['eta_min_days'], $read[0]['methods']['standard']['eta_max_days']));
pin('a missing label takes the default', 'Standard delivery', $read[0]['methods']['standard']['label']);
pin('express is off unless asked', false, $read[0]['methods']['express']['on']);

$many = array();
for ($i = 0; $i < 70; $i++) {
    $many[] = array('id' => 'z' . $i, 'name' => 'Z');
}
pin('at most 50 zones are read', 50, count(Zones::parse(json_encode($many))));

exit(harness_result());
