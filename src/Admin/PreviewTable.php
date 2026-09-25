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

namespace mindstellar\listingimport\Admin;

use Category;
use mindstellar\listingimport\Import\Importer;
use mindstellar\listingimport\Import\Source;
use mindstellar\listingimport\Plugin;

/**
 * The first records of a feed or file, each placed on this site as an import would place
 * it. A dry run: nothing is written.
 */
final class PreviewTable
{
    public const LIMIT = 20;

    /**
     * @param Source                         $source
     * @param array<int,array<string,mixed>> $records
     *
     * @return void
     */
    public static function render(Source $source, array $records): void
    {
        $importer = Plugin::importer();
        $words    = array(
            Importer::CREATED   => array('active', __('New', 'listing-import')),
            Importer::UPDATED   => array('pending', __('Changed', 'listing-import')),
            Importer::UNCHANGED => array('inactive', __('Same', 'listing-import')),
            Importer::FAILED    => array('error', __('Not importable', 'listing-import')),
        );
        $cols = array(
            'record'   => __('Record', 'listing-import'),
            'status'   => __('Would be', 'listing-import'),
            'category' => __('Category', 'listing-import'),
            'place'    => __('Place', 'listing-import'),
            'price'    => __('Price', 'listing-import'),
            'notes'    => __('Notes', 'listing-import'),
        );
        echo '<div class="table-contains-actions osc-table-stack"><table class="table"><thead><tr>';
        foreach ($cols as $label) {
            echo '<th>' . osc_esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_slice($records, 0, self::LIMIT) as $record) {
            $result   = $importer->import($source, $record, 0, true);
            $fields   = $result['fields'] ?? array();
            $title    = is_array($record['title'] ?? null) ? (string)reset($record['title']) : (string)($record['title'] ?? '');
            $notes    = $result['errors'] + $result['warnings'];
            $category = empty($fields['catId']) ? array() : (array)Category::newInstance()->findByPrimaryKey((int)$fields['catId']);
            [$state, $word] = $words[$result['status']];

            echo '<tr><td data-col-name="' . osc_esc_html($cols['record']) . '">' . osc_esc_html($title)
                . '<br><small class="text-muted text-break">' . osc_esc_html($result['external_id']) . '</small></td>';
            echo '<td data-col-name="' . osc_esc_html($cols['status']) . '">';
            osc_admin_status($state, $word);
            echo '</td><td data-col-name="' . osc_esc_html($cols['category']) . '">' . osc_esc_html((string)($category['s_name'] ?? '')) . '</td>';
            echo '<td data-col-name="' . osc_esc_html($cols['place']) . '">'
                . osc_esc_html(implode(', ', array_filter(array($fields['city'] ?? '', $fields['region'] ?? '', $fields['countryId'] ?? '')))) . '</td>';
            echo '<td data-col-name="' . osc_esc_html($cols['price']) . '">' . osc_esc_html(trim(($fields['price'] ?? '') . ' ' . ($fields['currency'] ?? ''))) . '</td>';
            echo '<td data-col-name="' . osc_esc_html($cols['notes']) . '"><small>' . implode('<br>', array_map(
                static fn ($field, $message) => osc_esc_html($field . ': ' . $message),
                array_keys($notes),
                $notes
            )) . '</small></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
