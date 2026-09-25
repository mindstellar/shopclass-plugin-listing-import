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

use mindstellar\listingimport\Admin\Guard;
use mindstellar\listingimport\Admin\Sources;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Import\Importer;
use mindstellar\listingimport\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Guard::view(__('Only an administrator can manage import sources.', 'listing-import'))) {
    return;
}

$source = (new DbStore())->source(Params::getParamInt('id'));
osc_admin_page_head(__('Feed preview', 'listing-import'), array(
    array('label' => __('Back to sources', 'listing-import'), 'url' => osc_route_admin_url(Sources::ROUTE), 'variant' => 'dim'),
));
if ($source === null || $source->kind !== 'pull') {
    osc_admin_empty(array('icon' => 'bi-rss', 'title' => __('That source has no feed to preview.', 'listing-import')));

    return;
}

// A dry run: the feed is fetched and each record is placed on this site, but nothing is written.
$read = Plugin::pull()->read($source);
osc_admin_form_section($source->name, array(
    'spaced' => true,
    'intro'  => $read['error'] === null
        ? sprintf(__('%1$d records in the feed. The first %2$d, as they would be imported. Nothing has been changed.', 'listing-import'), count($read['records']), min(20, count($read['records'])))
        : __('The feed could not be read.', 'listing-import'),
));
if ($read['error'] !== null) {
    osc_admin_empty(array('icon' => 'bi-exclamation-triangle', 'title' => $read['error']));

    return;
}
$importer = Plugin::importer();
$words    = array(
    Importer::CREATED   => array('active', __('New', 'listing-import')),
    Importer::UPDATED   => array('pending', __('Changed', 'listing-import')),
    Importer::UNCHANGED => array('inactive', __('Same', 'listing-import')),
    Importer::FAILED    => array('error', __('Not importable', 'listing-import')),
);
?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <th><?php _e('Record', 'listing-import'); ?></th>
            <th><?php _e('Would be', 'listing-import'); ?></th>
            <th><?php _e('Category', 'listing-import'); ?></th>
            <th><?php _e('Place', 'listing-import'); ?></th>
            <th><?php _e('Price', 'listing-import'); ?></th>
            <th><?php _e('Notes', 'listing-import'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($read['records'], 0, 20) as $record) {
            $result = $importer->import($source, $record, 0, true);
            $fields = $result['fields'] ?? array();
            $title  = is_array($record['title'] ?? null) ? (string)reset($record['title']) : (string)($record['title'] ?? '');
            $notes  = $result['errors'] + $result['warnings'];
            [$state, $word] = $words[$result['status']]; ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Record', 'listing-import')); ?>"><?php echo osc_esc_html($title); ?><br><small class="text-muted text-break"><?php echo osc_esc_html($result['external_id']); ?></small></td>
                <td data-col-name="<?php echo osc_esc_html(__('Would be', 'listing-import')); ?>"><?php osc_admin_status($state, $word); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Category', 'listing-import')); ?>"><?php
                    $category = empty($fields['catId']) ? array() : (array)Category::newInstance()->findByPrimaryKey((int)$fields['catId']);
                    echo osc_esc_html((string)($category['s_name'] ?? '')); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Place', 'listing-import')); ?>"><?php echo osc_esc_html(implode(', ', array_filter(array($fields['city'] ?? '', $fields['region'] ?? '', $fields['countryId'] ?? '')))); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Price', 'listing-import')); ?>"><?php echo osc_esc_html(trim(($fields['price'] ?? '') . ' ' . ($fields['currency'] ?? ''))); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Notes', 'listing-import')); ?>"><small><?php
                    echo implode('<br>', array_map(
                        static fn ($field, $message) => osc_esc_html($field . ': ' . $message),
                        array_keys($notes),
                        $notes
                    )); ?></small></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php
