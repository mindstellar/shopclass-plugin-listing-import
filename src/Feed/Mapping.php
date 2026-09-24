<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listingimport\Feed;

/**
 * Turns a feed's own field names into ours, then flat names into the nested record.
 *
 * A mapping is written one per line, "their name = our name": "Headline = title",
 * "Town = location.city". A dotted name nests, so "price.amount" and "price.currency" make
 * one price. A name with no line in the mapping keeps its own name.
 */
final class Mapping
{
    /** @var array<string,string> their name => our name */
    private array $names = array();

    /**
     * @param string $text the mapping as the admin wrote it
     */
    public function __construct(string $text)
    {
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $this->names[mb_strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
    }

    /**
     * A flat row of their fields as one of our records.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function apply(array $row): array
    {
        $record = array();
        foreach ($row as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $ours = $this->names[mb_strtolower(trim((string)$name))] ?? trim((string)$name);
            if ($ours === 'images' && is_string($value)) {
                // A cell or field holding several addresses, separated by spaces, commas or bars.
                $value = array_values(array_filter(preg_split('/[\s,|]+/', $value), 'strlen'));
            }
            self::put($record, explode('.', $ours), $value);
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $record
     * @param array<int,string>   $path
     * @param mixed               $value
     *
     * @return void
     */
    private static function put(array &$record, array $path, $value): void
    {
        $key = array_shift($path);
        if ($path === array()) {
            $record[$key] = $value;

            return;
        }
        if (!isset($record[$key]) || !is_array($record[$key])) {
            $record[$key] = array();
        }
        self::put($record[$key], $path, $value);
    }
}
