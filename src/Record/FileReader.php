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

namespace mindstellar\listingimport\Record;

/**
 * Records from a file: a JSON list, a JSON object holding one list of records (such as
 * {"records": [...]} or {"products": [...]}), or NDJSON (one JSON record per line).
 */
final class FileReader
{
    /**
     * @param string $path
     *
     * @return array{records: array<int,array<string,mixed>>, error: ?string}
     */
    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return array('records' => array(), 'error' => 'Cannot read ' . $path . '.');
        }
        $text = (string)file_get_contents($path);
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }

        $data = json_decode($text, true, 64);
        if (is_array($data)) {
            $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : self::wrapped($data);
            if ($records === null) {
                return array('records' => array(), 'error' => 'The file holds several lists; it must hold one list of records.');
            }
            if ($records !== array() && Validator::isList($records)) {
                return array('records' => array_values(array_filter($records, 'is_array')), 'error' => null);
            }
            if ($records !== array() && isset($records['external_id'])) {
                return array('records' => array($records), 'error' => null);
            }
        }

        // NDJSON: every non-empty line is one record.
        $records = array();
        foreach (preg_split('/\r\n|\n|\r/', $text) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true, 64);
            if (!is_array($record)) {
                return array('records' => array(), 'error' => 'Line ' . ($number + 1) . ' is not a JSON record.');
            }
            $records[] = $record;
        }

        return $records === array()
            ? array('records' => array(), 'error' => 'The file holds no records.')
            : array('records' => $records, 'error' => null);
    }

    /**
     * The one list of records an object wraps, the object itself when it holds none, or null
     * when it holds several.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>|null
     */
    private static function wrapped(array $data): ?array
    {
        $lists = array_filter($data, static fn ($value) => is_array($value) && $value !== array()
            && Validator::isList($value) && is_array($value[0]));

        if (Validator::isList($data) || $lists === array()) {
            return $data;
        }

        return count($lists) === 1 ? reset($lists) : null;
    }
}
