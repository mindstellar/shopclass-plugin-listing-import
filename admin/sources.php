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

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Guard::view(__('Only an administrator can manage import sources.', 'listing-import'))) {
    return;
}

$sources = Sources::all();
$listUrl = osc_route_admin_url(Sources::ROUTE);
$formats = array(
    'json'          => 'JSON',
    'ndjson'        => 'NDJSON',
    'csv'           => 'CSV',
    'shopclass-rss' => __('Shopclass RSS', 'listing-import'),
);

$rowLink = static fn (string $url, string $text, string $class = ''): string
    => '<a href="' . osc_esc_html($url) . '"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . osc_esc_html($text) . '</a>';
$dialog  = static fn (string $id, string $text, string $class = ''): string
    => '<a href="#" onclick="document.getElementById(\'' . $id . '\').showModal(); return false;"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . osc_esc_html($text) . '</a>';

osc_admin_page_head(__('Import sources', 'listing-import'));
?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <th><?php _e('Name', 'listing-import'); ?></th>
            <th><?php _e('How', 'listing-import'); ?></th>
            <th><?php _e('Status', 'listing-import'); ?></th>
            <th><?php _e('Listings', 'listing-import'); ?></th>
            <th><?php _e('Last fetch', 'listing-import'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($sources === array()) {
            osc_admin_table_empty(5, array(
                'icon'  => 'bi-box-arrow-in-down',
                'title' => __('No sources yet', 'listing-import'),
                'text'  => __('Add a source for a partner who sends listings, or for a feed this site fetches.', 'listing-import'),
            ));
        }
        foreach ($sources as $s) {
            $id      = (int)$s['pk_i_id'];
            $pull    = $s['e_kind'] === 'pull';
            $actions = array($rowLink(osc_route_admin_url(Sources::EDIT_ROUTE, array('id' => $id)), __('Edit', 'listing-import')));
            if ($pull) {
                $actions[] = $rowLink(osc_route_admin_url(Sources::PREVIEW_ROUTE, array('id' => $id)), __('Preview', 'listing-import'));
                $actions[] = $dialog('li-fetch-' . $id, __('Fetch now', 'listing-import'));
            }
            $actions[] = $dialog('li-delete-' . $id, __('Delete', 'listing-import')); ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Name', 'listing-import')); ?>"><?php echo osc_esc_html($s['s_name']); ?>
                    <div class="actions"><ul><li><?php echo implode('</li><li>', $actions); ?></li></ul></div></td>
                <td data-col-name="<?php echo osc_esc_html(__('How', 'listing-import')); ?>"><?php
                    echo $pull
                        ? osc_esc_html(sprintf(__('Fetches %1$s every %2$d min', 'listing-import'), $formats[$s['s_format']] ?? $s['s_format'], (int)$s['i_interval_minutes']))
                          . '<br><small class="text-muted text-break">' . osc_esc_html($s['s_url']) . '</small>'
                        : osc_esc_html(sprintf(__('API, %d active keys', 'listing-import'), (int)$s['i_keys'])); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Status', 'listing-import')); ?>"><?php
                    (int)$s['b_enabled'] === 1
                        ? osc_admin_status('active', __('On', 'listing-import'))
                        : osc_admin_status('inactive', __('Off', 'listing-import')); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Listings', 'listing-import')); ?>"><?php echo (int)$s['i_listings']; ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Last fetch', 'listing-import')); ?>"><?php
                    echo $pull && $s['dt_last_run'] !== null
                        ? osc_esc_html(osc_format_date($s['dt_last_run']) . ' ' . date('H:i', strtotime($s['dt_last_run']))) . '<br><small class="text-muted">' . osc_esc_html($s['s_last_status']) . '</small>'
                        : '&mdash;'; ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php
foreach ($sources as $s) {
    $id = (int)$s['pk_i_id'];
    if ($s['e_kind'] === 'pull') {
        osc_admin_confirm_dialog(array(
            'id'      => 'li-fetch-' . $id,
            'url'     => $listUrl,
            'fields'  => array('li_do' => 'fetch', 'id' => $id),
            'title'   => sprintf(__('Fetch "%s" now?', 'listing-import'), $s['s_name']),
            'text'    => __('The fetch runs with the next background jobs, and imports what the feed holds.', 'listing-import'),
            'confirm' => __('Fetch now', 'listing-import'),
            'tone'    => 'plain',
        ));
    }
    osc_admin_confirm_dialog(array(
        'id'      => 'li-delete-' . $id,
        'url'     => $listUrl,
        'fields'  => array('li_do' => 'delete', 'id' => $id),
        'title'   => sprintf(__('Delete "%s"?', 'listing-import'), $s['s_name']),
        'text'    => __('Its listings stay on the site, but they are no longer updated from this source.', 'listing-import'),
        'confirm' => __('Delete', 'listing-import'),
    ));
}
