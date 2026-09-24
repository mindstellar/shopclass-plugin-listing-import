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
 * Records from a file: a JSON list, a JSON object with a "records" list, or NDJSON (one
 * JSON record per line).
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
            $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : $data;
            if (array_keys($records) === range(0, count($records) - 1) && $records !== array()) {
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
}
