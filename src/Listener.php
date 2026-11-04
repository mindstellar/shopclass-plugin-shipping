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

namespace mindstellar\shipping;

use mindstellar\billing\event\CarriersListing;
use mindstellar\billing\event\CartFreeOver;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\event\TrackingUrlResolving;

/**
 * The typed cart and tracking listeners. Each hands the event's data to Plugin and writes the answer back.
 */
final class Listener
{
    public function onFreeOver(CartFreeOver $event): void
    {
        $core   = $event->freeOver();
        $amount = Plugin::threshold($event->cartArray(), is_array($core) ? (int) ($core['amount'] ?? 0) : 0);
        if ($amount !== null) {
            $event->setFreeOver($amount <= 0 ? null : $amount);
        }
    }

    public function onProblems(CartProblems $event): void
    {
        $problem = Plugin::blocked($event->cartArray());
        if ($problem !== null) {
            $event->add($problem['code'], $problem['pointer'], $problem['message']);
        }
    }

    public function onTrackingUrl(TrackingUrlResolving $event): void
    {
        $event->setUrl(Carriers::trackingUrl(Plugin::config()->carriers, $event->url(), $event->carrier(), $event->tracking()));
    }

    public function onCarriers(CarriersListing $event): void
    {
        foreach (Plugin::carrierNames() as $name) {
            $event->add($name);
        }
    }
}
