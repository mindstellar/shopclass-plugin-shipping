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

namespace mindstellar\shipping\Admin;

use mindstellar\shipping\Zones;

/**
 * Turns a posted zone into the stored shape and says what is wrong with it. Pure: it takes the
 * posted values as an array, so tests need no request.
 */
final class ZoneForm
{
    /**
     * @param array<string,mixed> $post      field name => posted value (strings)
     * @param callable            $toMicros  fn(string): ?int, a typed amount as micros or null
     * @param string              $id        the zone's id
     *
     * @return array{zone:array<string,mixed>,errors:string[]}
     */
    public static function read(array $post, callable $toMicros, string $id): array
    {
        $errors = array();
        $name   = trim((string) ($post['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > Zones::MAX_NAME) {
            $errors[] = sprintf(__('Give the zone a name of up to %d characters.', 'shipping'), Zones::MAX_NAME);
        }
        $countries = array();
        foreach (self::split((string) ($post['countries'] ?? ''), ',') as $code) {
            $code = strtoupper($code);
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                $errors[] = sprintf(__('"%s" is not a two-letter country code.', 'shipping'), $code);
                continue;
            }
            $countries[] = $code;
        }
        $states   = self::lines((string) ($post['states'] ?? ''), Zones::MAX_TEXT, __('A state', 'shipping'), $errors);
        $prefixes = self::lines((string) ($post['postcode_prefixes'] ?? ''), 20, __('A postcode start', 'shipping'), $errors);

        $classes = self::classes((string) ($post['class_extra'] ?? ''), $toMicros, $errors);

        $methods = array();
        foreach (Zones::METHODS as $key) {
            $methods[$key] = self::method($post, $key, $toMicros, $errors);
        }
        if (!$methods['standard']['on'] && !$methods['express']['on']) {
            $errors[] = __('Switch on at least one delivery method.', 'shipping');
        }

        $zone = Zones::clean(array(
            'id'                => $id,
            'name'              => $name,
            'countries'         => $countries,
            'states'            => $states,
            'postcode_prefixes' => $prefixes,
            'methods'           => $methods,
            'class_extra'       => $classes,
        ));

        return array('zone' => $zone ?? array(), 'errors' => array_values(array_unique($errors)));
    }

    /**
     * @param array<string,mixed> $post
     * @param string[]            $errors
     *
     * @return array<string,mixed>
     */
    private static function method(array $post, string $key, callable $toMicros, array &$errors): array
    {
        $p       = static fn (string $field): string => trim((string) ($post[$key . '_' . $field] ?? ''));
        $heading = $key === 'express' ? __('Express', 'shipping') : __('Standard', 'shipping');
        $method  = array('on' => $p('on') === '1', 'label' => $p('label'));
        if (mb_strlen($method['label'], 'UTF-8') > Zones::MAX_LABEL) {
            $errors[] = sprintf(__('%1$s: the name is longer than %2$d characters.', 'shipping'), $heading, Zones::MAX_LABEL);
        }
        foreach (array('amount' => __('price', 'shipping'), 'free_over' => __('free over', 'shipping'), 'per_kg' => __('price per extra kg', 'shipping')) as $field => $what) {
            $micros = $p($field) === '' ? 0 : $toMicros($p($field));
            if ($micros === null || $micros < 0 || $micros > Zones::MAX_AMOUNT) {
                $errors[] = sprintf(__('%1$s: the %2$s is not a valid amount.', 'shipping'), $heading, $what);
                $micros   = 0;
            }
            $method[$field] = $micros;
        }
        foreach (array('base_g' => array(Zones::MAX_GRAMS, __('weight included', 'shipping')), 'eta_min_days' => array(Zones::MAX_DAYS, __('fastest days', 'shipping')), 'eta_max_days' => array(Zones::MAX_DAYS, __('slowest days', 'shipping'))) as $field => [$max, $what]) {
            $value = $p($field) === '' ? 0 : $p($field);
            if (!is_int($value) && (preg_match('/^\d{1,9}$/', $value) !== 1 || (int) $value > $max)) {
                $errors[] = sprintf(__('%1$s: the %2$s must be a whole number up to %3$d.', 'shipping'), $heading, $what, $max);
                $value    = 0;
            }
            $method[$field] = (int) $value;
        }
        if ($method['eta_min_days'] > $method['eta_max_days']) {
            $errors[] = sprintf(__('%s: the fastest days are more than the slowest.', 'shipping'), $heading);
        }

        return $method;
    }

    /**
     * "name = amount" lines into class => micros.
     *
     * @param string[] $errors
     *
     * @return array<string,int>
     */
    private static function classes(string $text, callable $toMicros, array &$errors): array
    {
        $out = array();
        foreach (self::split(str_replace("\r", '', $text), "\n") as $line) {
            $parts = explode('=', $line, 2);
            $name  = mb_strtolower(trim($parts[0]), 'UTF-8');
            if (count($parts) !== 2 || $name === '' || mb_strlen($name, 'UTF-8') > Zones::MAX_CLASS) {
                $errors[] = sprintf(__('Write each shipping class as a name of up to %d characters, then = and the charge.', 'shipping'), Zones::MAX_CLASS);
                continue;
            }
            if ($name === Zones::FREE_CLASS) {
                $errors[] = __('The "free" class always ships free, so it takes no charge.', 'shipping');
                continue;
            }
            $micros = $toMicros(trim($parts[1]));
            if ($micros === null || $micros < 0 || $micros > Zones::MAX_AMOUNT) {
                $errors[] = sprintf(__('The charge for "%s" is not a valid amount.', 'shipping'), $name);
                continue;
            }
            $out[$name] = $micros;
        }
        if (count($out) > Zones::MAX_CLASSES) {
            $errors[] = sprintf(__('At most %d shipping classes.', 'shipping'), Zones::MAX_CLASSES);
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private static function split(string $text, string $separator): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, $text)), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param string[] $errors
     *
     * @return string[]
     */
    private static function lines(string $text, int $max, string $what, array &$errors): array
    {
        $out = array();
        foreach (self::split(str_replace("\r", '', $text), "\n") as $line) {
            if (mb_strlen($line, 'UTF-8') > $max) {
                $errors[] = sprintf(__('%1$s is longer than %2$d characters.', 'shipping'), $what, $max);
                continue;
            }
            $out[] = $line;
        }
        if (count($out) > Zones::MAX_LIST) {
            $errors[] = sprintf(__('%1$s: at most %2$d lines.', 'shipping'), $what, Zones::MAX_LIST);
        }

        return $out;
    }
}
