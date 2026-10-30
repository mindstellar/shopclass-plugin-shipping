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
 * The zone list: reading what is stored (never trusting it) and finding the zone for an address.
 * Pure: no core, no database.
 */
final class Zones
{
    public const MAX_ZONES = 50;

    public const METHODS = array('standard', 'express');

    public const MAX_NAME = 80;

    public const MAX_LABEL = 60;

    public const MAX_LIST = 250;

    public const MAX_TEXT = 100;

    public const MAX_AMOUNT = 1000000000000;

    public const MAX_GRAMS = 10000000;

    public const MAX_DAYS = 365;

    /** Items in this shipping class ship free and add no weight. */
    public const FREE_CLASS = 'free';

    public const MAX_CLASSES = 50;

    /** The length of a variant's shipping class in core. */
    public const MAX_CLASS = 40;

    /**
     * The stored JSON as clean zones. A malformed value reads as no zones; a malformed zone is
     * left out, a malformed field takes its default.
     *
     * @param mixed $stored
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parse($stored): array
    {
        $list = is_array($stored) ? $stored : (is_string($stored) && $stored !== '' ? json_decode($stored, true) : null);
        if (!is_array($list)) {
            return array();
        }
        $zones = array();
        $seen  = array();
        foreach ($list as $raw) {
            if (count($zones) >= self::MAX_ZONES) {
                break;
            }
            $zone = self::clean($raw);
            if ($zone === null || isset($seen[$zone['id']])) {
                continue;
            }
            $seen[$zone['id']] = true;
            $zones[]           = $zone;
        }

        return $zones;
    }

    /**
     * One zone cleaned to the stored shape, or null when it has no usable id.
     *
     * @param mixed $raw
     *
     * @return array<string,mixed>|null
     */
    public static function clean($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $id = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $id) !== 1) {
            return null;
        }
        $name    = self::text($raw['name'] ?? '', self::MAX_NAME);
        $methods = array();
        foreach (self::METHODS as $key) {
            $methods[$key] = self::method(is_array($raw['methods'] ?? null) ? ($raw['methods'][$key] ?? null) : null, $key);
        }

        return array(
            'id'                => $id,
            'name'              => $name === '' ? $id : $name,
            'countries'         => self::countries($raw['countries'] ?? array()),
            'states'            => self::strings($raw['states'] ?? array(), self::MAX_TEXT),
            'postcode_prefixes' => array_values(array_filter(
                array_map(static fn (string $p): string => self::postcode($p), self::strings($raw['postcode_prefixes'] ?? array(), 20)),
                static fn (string $p): bool => $p !== ''
            )),
            'methods'           => $methods,
            'class_extra'       => self::classCharges($raw['class_extra'] ?? array()),
        );
    }

    /**
     * Shipping class => extra charge in micros; names lower-cased, the free class and bad rows dropped.
     *
     * @param mixed $raw
     *
     * @return array<string,int>
     */
    public static function classCharges($raw): array
    {
        $out = array();
        foreach (is_array($raw) ? $raw : array() as $name => $amount) {
            $name = is_string($name) ? mb_strtolower(trim($name), 'UTF-8') : '';
            if ($name === '' || $name === self::FREE_CLASS || mb_strlen($name, 'UTF-8') > self::MAX_CLASS || count($out) >= self::MAX_CLASSES) {
                continue;
            }
            $out[$name] = self::int($amount, self::MAX_AMOUNT);
        }

        return $out;
    }

    /**
     * @param mixed $raw
     *
     * @return array<string,mixed>
     */
    private static function method($raw, string $key): array
    {
        $raw   = is_array($raw) ? $raw : array();
        $label = self::text($raw['label'] ?? '', self::MAX_LABEL);
        $min   = self::int($raw['eta_min_days'] ?? 0, self::MAX_DAYS);
        $max   = self::int($raw['eta_max_days'] ?? 0, self::MAX_DAYS);

        return array(
            'on'           => ($raw['on'] ?? false) === true || ($raw['on'] ?? null) === 1 || ($raw['on'] ?? null) === '1',
            'label'        => $label !== '' ? $label : self::defaultLabel($key),
            'amount'       => self::int($raw['amount'] ?? 0, self::MAX_AMOUNT),
            'free_over'    => self::int($raw['free_over'] ?? 0, self::MAX_AMOUNT),
            'base_g'       => self::int($raw['base_g'] ?? 0, self::MAX_GRAMS),
            'per_kg'       => self::int($raw['per_kg'] ?? 0, self::MAX_AMOUNT),
            'eta_min_days' => min($min, $max),
            'eta_max_days' => max($min, $max),
        );
    }

    public static function defaultLabel(string $key): string
    {
        return $key === 'express' ? 'Express delivery' : 'Standard delivery';
    }

    /**
     * Whether a zone has a method switched on.
     *
     * @param array<string,mixed> $zone
     */
    public static function serves(array $zone): bool
    {
        foreach (self::METHODS as $key) {
            if (!empty($zone['methods'][$key]['on'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $zones
     *
     * @return array<string,mixed>|null
     */
    public static function find(array $zones, string $id): ?array
    {
        foreach ($zones as $zone) {
            if ($zone['id'] === $id) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * The zone for an address. A postcode prefix beats a state, a state beats a country; among
     * zones of the same strength the first in list order wins. A zone with no method on never
     * matches. An address part the shopper has not given yet (no country) does not rule a zone out;
     * a state or postcode list needs the part it tests.
     *
     * @param array<int,array<string,mixed>> $zones
     *
     * @return array<string,mixed>|null
     */
    public static function match(array $zones, string $country, string $state, string $postcode): ?array
    {
        $country  = strtoupper(trim($country));
        $state    = self::state($state);
        $postcode = self::postcode($postcode);
        $best     = null;
        $bestRank = 0;
        foreach ($zones as $zone) {
            $rank = self::serves($zone) ? self::rank($zone, $country, $state, $postcode) : 0;
            if ($rank > $bestRank) {
                $best     = $zone;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * Whether a zone could still serve the address once the shopper gives the state or postcode
     * still missing from it.
     *
     * @param array<int,array<string,mixed>> $zones
     */
    public static function awaits(array $zones, string $country, string $state, string $postcode): bool
    {
        $country  = strtoupper(trim($country));
        $state    = self::state($state);
        $postcode = self::postcode($postcode);
        if ($state !== '' && $postcode !== '') {
            return false;
        }
        foreach ($zones as $zone) {
            if (!self::serves($zone)
                || ($zone['countries'] !== array() && $country !== '' && !in_array($country, $zone['countries'], true))
                || ($state !== '' && $zone['states'] !== array() && !in_array($state, array_map(array(self::class, 'state'), $zone['states']), true))
            ) {
                continue;
            }
            if (($zone['states'] !== array() && $state === '') || ($zone['postcode_prefixes'] !== array() && $postcode === '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $zone
     */
    private static function rank(array $zone, string $country, string $state, string $postcode): int
    {
        if ($zone['countries'] !== array() && $country !== '' && !in_array($country, $zone['countries'], true)) {
            return 0;
        }
        if ($zone['states'] !== array() && ($state === '' || !in_array($state, array_map(array(self::class, 'state'), $zone['states']), true))) {
            return 0;
        }
        if ($zone['postcode_prefixes'] !== array()) {
            if ($postcode === '') {
                return 0;
            }
            foreach ($zone['postcode_prefixes'] as $prefix) {
                if (strncmp($postcode, $prefix, strlen($prefix)) === 0) {
                    return 3;
                }
            }

            return 0;
        }

        return $zone['states'] !== array() ? 2 : 1;
    }

    /** A state for comparing: lower case, no spaces. */
    public static function state(string $state): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($state), 'UTF-8')) ?? '';
    }

    /** A postcode for comparing: upper case, no spaces. */
    public static function postcode(string $postcode): string
    {
        return preg_replace('/\s+/u', '', strtoupper(trim($postcode))) ?? '';
    }

    /**
     * @param mixed $value
     *
     * @return string[]
     */
    private static function countries($value): array
    {
        $out = array();
        foreach (self::strings($value, 2) as $code) {
            $code = strtoupper($code);
            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $out[$code] = $code;
            }
        }

        return array_values($out);
    }

    /**
     * @param mixed $value
     *
     * @return string[]
     */
    private static function strings($value, int $max): array
    {
        $out = array();
        foreach (is_array($value) ? $value : array() as $item) {
            if (count($out) >= self::MAX_LIST) {
                break;
            }
            $text = self::text($item, $max);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function text($value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags($value)) ?? '');

        return mb_substr($value, 0, $max, 'UTF-8');
    }

    /**
     * @param mixed $value
     */
    private static function int($value, int $max): int
    {
        if (is_string($value) && preg_match('/^\d{1,15}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $value = (int) round($value);
        }

        return is_int($value) ? max(0, min($max, $value)) : 0;
    }
}
