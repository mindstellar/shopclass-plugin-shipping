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

/**
 * The plugin's settings as plain data. Plugin::config() reads them from preferences; tests build one by hand.
 */
final class Config
{
    public const BLOCK = 'block';

    public const STANDARD = 'standard';

    /**
     * @param array<int,array<string,mixed>>           $zones
     * @param array<int,array{name:string,url:string}> $carriers
     */
    public function __construct(
        public array $zones = array(),
        public string $defaultZone = '',
        public string $noZone = self::BLOCK,
        public array $carriers = array(),
        public int $weightDefaultGrams = 500,
        public bool $imperial = false
    ) {
    }
}
