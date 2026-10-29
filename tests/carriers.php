<?php
/*
 * This file is part of the Shipping plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Courier tracking links: https only, the number encoded, unknown carriers left alone.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shipping\Carriers;

harness_section('reading the list');
$text = "Delhivery, https://www.delhivery.com/track/package/{tracking}\n"
    . "Old Courier, http://old.example.com/t/{tracking}\n"
    . "No Number, https://example.com/track\n"
    . "just words\n"
    . "  BlueDart ,https://bluedart.example.com/?n={tracking}  \n"
    . "Bad Host, https:///{tracking}\n"
    . "Script, https://x.example.com/<script>{tracking}\n";
$list = Carriers::parse($text);
pin('only good https lines with {tracking} are kept', array('Delhivery', 'BlueDart'), array_column($list, 'name'));
pin('a JSON list reads too', array('A'), array_column(Carriers::parse('[{"name":"A","url":"https://a.example.com/{tracking}"},{"name":"B","url":"http://b.example.com/{tracking}"}]'), 'name'));
pin('garbage reads as none', array(), Carriers::parse('[[['));
pin('empty reads as none', array(), Carriers::parse(''));

harness_section('the link');
pin('the number is put in', 'https://www.delhivery.com/track/package/AB123', Carriers::trackingUrl($list, '', 'Delhivery', 'AB123'));
pin('the carrier name ignores case', 'https://bluedart.example.com/?n=77', Carriers::trackingUrl($list, '', ' bluedart ', '77'));
pin('the number is URL-encoded', 'https://www.delhivery.com/track/package/a%2Fb%3Fc%23d', Carriers::trackingUrl($list, '', 'Delhivery', 'a/b?c#d'));
pin('an http carrier is refused', '', Carriers::trackingUrl($list, '', 'Old Courier', '1'));
pin('an unknown carrier keeps the url it came with', 'https://given.example/x', Carriers::trackingUrl($list, 'https://given.example/x', 'Nobody', '1'));
pin('no tracking number keeps it too', '', Carriers::trackingUrl($list, '', 'Delhivery', ' '));
pin('an absurd number is not put in a link', '', Carriers::trackingUrl($list, '', 'Delhivery', str_repeat('9', 101)));

harness_section('the settings check');
pin('good text passes', null, Carriers::error($text === '' ? '' : "Delhivery, https://www.delhivery.com/{tracking}\n\n"));
check('an http line is named', str_contains((string) Carriers::error("A, https://a.example.com/{tracking}\nB, http://b.example.com/{tracking}"), 'Line 2'));
check('a line without a comma is refused', Carriers::error('Delhivery https://a.example.com/{tracking}') !== null);

exit(harness_result());
