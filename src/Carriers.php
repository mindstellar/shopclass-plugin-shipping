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
 * Courier tracking links: a name and an https URL with {tracking} in it. Pure.
 */
final class Carriers
{
    public const MAX = 50;

    /**
     * The carriers from the stored text: one "Name, URL" per line, or a JSON list of {name, url}.
     * Lines that are not an https URL with {tracking} are left out.
     *
     * @param mixed $stored
     *
     * @return array<int,array{name:string,url:string}>
     */
    public static function parse($stored): array
    {
        $rows = array();
        if (is_array($stored)) {
            $rows = $stored;
        } elseif (is_string($stored) && ltrim($stored) !== '' && ltrim($stored)[0] === '[') {
            $decoded = json_decode($stored, true);
            $rows    = is_array($decoded) ? $decoded : array();
        } elseif (is_string($stored)) {
            foreach (preg_split('/\R/', $stored) ?: array() as $line) {
                $comma = strpos($line, ',');
                if ($comma !== false) {
                    $rows[] = array('name' => substr($line, 0, $comma), 'url' => substr($line, $comma + 1));
                }
            }
        }
        $out = array();
        foreach ($rows as $row) {
            $name = is_array($row) && is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $url  = is_array($row) && is_string($row['url'] ?? null) ? trim($row['url']) : '';
            if ($name === '' || mb_strlen($name, 'UTF-8') > 60 || self::url($url) === null || count($out) >= self::MAX) {
                continue;
            }
            $out[] = array('name' => $name, 'url' => $url);
        }

        return $out;
    }

    /**
     * The error for a "Name, URL" textarea, or null when every non-empty line is good.
     */
    public static function error(string $text): ?string
    {
        $n = 0;
        foreach (preg_split('/\R/', $text) ?: array() as $line) {
            if (trim($line) === '') {
                continue;
            }
            $n++;
            $comma = strpos($line, ',');
            $name  = $comma === false ? '' : trim(substr($line, 0, $comma));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 60 || self::url(substr($line, (int) $comma + 1)) === null) {
                return sprintf(__('Line %d should be a name, a comma, and an https:// link containing {tracking}.', 'shipping'), $n);
            }
        }

        return $n > self::MAX ? sprintf(__('At most %d carriers.', 'shipping'), self::MAX) : null;
    }

    /**
     * The link for a tracking number, or $url unchanged when the carrier is not on the list.
     *
     * @param array<int,array{name:string,url:string}> $carriers
     */
    public static function trackingUrl(array $carriers, string $url, string $carrier, string $tracking): string
    {
        $carrier  = mb_strtolower(trim($carrier), 'UTF-8');
        $tracking = trim($tracking);
        if ($carrier === '' || $tracking === '' || mb_strlen($tracking, 'UTF-8') > 100) {
            return $url;
        }
        foreach ($carriers as $row) {
            if (mb_strtolower($row['name'], 'UTF-8') === $carrier) {
                return str_replace('{tracking}', rawurlencode($tracking), $row['url']);
            }
        }

        return $url;
    }

    private static function url(string $url): ?string
    {
        $url = trim($url);
        if (strlen($url) > 500 || preg_match('/^https:\/\/[^\s<>"\']+$/i', $url) !== 1 || substr_count($url, '{tracking}') < 1) {
            return null;
        }
        $parts = parse_url(str_replace('{tracking}', 'x', $url));

        return is_array($parts) && ($parts['host'] ?? '') !== '' ? $url : null;
    }
}
