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
 * Prices and delivery dates for one method of a zone. Pure; amounts are micros.
 */
final class Rates
{
    /**
     * The method's price: its base amount plus per-kg for each started kilo above the base weight,
     * or nothing once the cart subtotal reaches the free-over amount.
     *
     * @param array<string,mixed> $method
     */
    public static function amount(array $method, int $totalGrams, int $subtotal): int
    {
        if (self::isFree($method, $subtotal)) {
            return 0;
        }
        $over = max(0, $totalGrams - (int) $method['base_g']);

        return (int) $method['amount'] + (int) $method['per_kg'] * intdiv($over + 999, 1000);
    }

    /**
     * @param array<string,mixed> $method
     */
    public static function isFree(array $method, int $subtotal): bool
    {
        return (int) $method['free_over'] > 0 && $subtotal >= (int) $method['free_over'];
    }

    /**
     * The cart's weight in grams. A line with no weight counts as the default weight.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    public static function grams(array $lines, int $defaultGrams): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $weight = isset($line['weight']) && is_numeric($line['weight']) && (int) $line['weight'] > 0 ? (int) $line['weight'] : $defaultGrams;
            $qty    = max(0, (int) ($line['qty'] ?? 0));
            $total += $weight * $qty;
        }

        return $total;
    }

    /**
     * The delivery estimate: "Tomorrow" for exactly one day, otherwise the date, or the two dates
     * of the range. Null when the method has no estimate.
     *
     * @param array<string,mixed> $method
     * @param callable            $format fn(int $timestamp): string
     */
    public static function eta(array $method, int $now, callable $format): ?string
    {
        $min = (int) $method['eta_min_days'];
        $max = (int) $method['eta_max_days'];
        if ($max <= 0) {
            return null;
        }
        if ($min === 1 && $max === 1) {
            return __('Tomorrow', 'shipping');
        }
        $from = $format(strtotime('+' . $min . ' days', $now));
        $to   = $format(strtotime('+' . $max . ' days', $now));

        return $from === $to ? $from : $from . ' – ' . $to;
    }
}
