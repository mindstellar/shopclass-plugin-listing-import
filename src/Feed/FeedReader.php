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

use mindstellar\listingimport\Record\FileReader;
use XMLReader;

/**
 * Reads a downloaded feed into records: JSON, NDJSON, CSV or another Shopclass site's RSS.
 */
final class FeedReader
{
    /** The most records one feed may hold. */
    public const MAX_RECORDS = 5000;

    /**
     * @param string $path    the downloaded file
     * @param string $format  json, ndjson, csv or shopclass-rss
     * @param string $mapping field names, as the admin wrote them
     *
     * @return array{records: array<int,array<string,mixed>>, error: ?string}
     */
    public static function read(string $path, string $format, string $mapping = ''): array
    {
        $map = new Mapping($mapping);
        switch ($format) {
            case 'json':
            case 'ndjson':
                $read = FileReader::read($path);
                if ($read['error'] === null) {
                    $read['records'] = array_map(array($map, 'apply'), $read['records']);
                }
                break;
            case 'csv':
                $read = self::csv($path, $map);
                break;
            case 'shopclass-rss':
                $read = self::rss($path);
                break;
            default:
                return array('records' => array(), 'error' => 'Unknown format ' . $format . '.');
        }
        if ($read['error'] === null && count($read['records']) > self::MAX_RECORDS) {
            return array('records' => array(), 'error' => 'The feed holds more than ' . self::MAX_RECORDS . ' records.');
        }

        return $read;
    }

    /**
     * A header row names the fields; each row after it is a record. The separator is
     * whichever of comma, semicolon, tab or bar the header uses most.
     *
     * @param string  $path
     * @param Mapping $map
     *
     * @return array{records: array<int,array<string,mixed>>, error: ?string}
     */
    private static function csv(string $path, Mapping $map): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return array('records' => array(), 'error' => 'Cannot read the feed.');
        }
        $first = (string)fgets($handle);
        if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) {
            $first = substr($first, 3);
        }
        $counts = array();
        foreach (array(',', ';', "\t", '|') as $separator) {
            $counts[$separator] = substr_count($first, $separator);
        }
        arsort($counts);
        $separator = (string)key($counts);
        $header    = array_map('trim', str_getcsv(rtrim($first, "\r\n"), $separator, '"', ''));
        if (count(array_filter($header, 'strlen')) === 0) {
            fclose($handle);

            return array('records' => array(), 'error' => 'The feed has no header row.');
        }

        $records = array();
        while (($row = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            if ($row === array(null) || count(array_filter($row, static fn ($cell) => trim((string)$cell) !== '')) === 0) {
                continue;
            }
            $flat = array();
            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $flat[$name] = trim((string)($row[$i] ?? ''));
                }
            }
            $records[] = $map->apply($flat);
            if (count($records) > self::MAX_RECORDS) {
                break;
            }
        }
        fclose($handle);

        return array('records' => $records, 'error' => $records === array() ? 'The feed holds no records.' : null);
    }

    /**
     * Another Shopclass site's RSS feed. Each item's guid is its external id, so the same
     * listing keeps its id from one fetch to the next.
     *
     * A document declaring a DOCTYPE or an entity is refused before it is parsed: that is
     * how external-entity and entity-expansion attacks arrive, and a real feed has neither.
     *
     * @param string $path
     *
     * @return array{records: array<int,array<string,mixed>>, error: ?string}
     */
    private static function rss(string $path): array
    {
        $head = (string)file_get_contents($path, false, null, 0, 4096);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            return array('records' => array(), 'error' => 'The feed declares a DOCTYPE or an entity; refused.');
        }

        $xml = new XMLReader();
        if (!@$xml->open($path, null, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return array('records' => array(), 'error' => 'The feed is not XML.');
        }
        $records = array();
        $isXml   = false;
        while (@$xml->read()) {
            $isXml = $isXml || $xml->nodeType === XMLReader::ELEMENT;
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'item') {
                continue;
            }
            $item = @simplexml_load_string($xml->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($item === false) {
                continue;
            }
            $record = self::rssItem($item);
            if ($record !== null) {
                $records[] = $record;
            }
            if (count($records) > self::MAX_RECORDS) {
                break;
            }
        }
        $xml->close();

        if (!$isXml) {
            return array('records' => array(), 'error' => 'The feed is not XML.');
        }
        if ($records === array()) {
            return array('records' => array(), 'error' => 'The feed holds no items.');
        }

        return array('records' => $records, 'error' => null);
    }

    /**
     * @param \SimpleXMLElement $item
     *
     * @return array<string,mixed>|null
     */
    private static function rssItem(\SimpleXMLElement $item): ?array
    {
        $id = trim((string)$item->guid) ?: trim((string)$item->link);
        if ($id === '') {
            return null;
        }
        // Core's feed opens each description with a thumbnail link; the listing's own text follows.
        $description = trim((string)preg_replace('#^\s*<a\b[^>]*>\s*<img\b[^>]*>\s*</a>\s*#i', '', (string)$item->description));

        $record = array(
            'external_id' => mb_substr($id, 0, 191),
            'title'       => trim((string)$item->title),
            'description' => $description,
            'category'    => array('label' => trim((string)$item->category)),
            'source_url'  => trim((string)$item->link),
        );
        $location = array_filter(array(
            'country'   => trim((string)$item->country),
            'region'    => trim((string)$item->region),
            'city'      => trim((string)$item->city),
            'city_area' => trim((string)$item->cityArea),
        ), 'strlen');
        if ($location !== array()) {
            $record['location'] = $location;
        }
        $image = (string)($item->enclosure['url'] ?? '');
        if ($image !== '') {
            $record['images'] = array($image);
        }
        if ($record['category']['label'] === '') {
            unset($record['category']);
        }

        return $record;
    }
}
