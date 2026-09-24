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

osc_admin_page_head(__('Import sources', 'listing-import'), array(
    array('label' => __('Add source', 'listing-import'), 'url' => osc_route_admin_url(Sources::EDIT_ROUTE), 'icon' => 'bi-plus-lg', 'variant' => 'primary'),
));

osc_admin_panel_open(__('Sources', 'listing-import'), array(
    'subtitle' => __('A push source takes listings a partner sends with an API key. A pull source fetches a feed on a schedule.', 'listing-import'),
));
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
            <th class="text-end"><?php _e('Actions', 'listing-import'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($sources as $s) {
            $id   = (int)$s['pk_i_id'];
            $pull = $s['e_kind'] === 'pull'; ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Name', 'listing-import')); ?>"><?php echo osc_esc_html($s['s_name']); ?></td>
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
                <td data-col-name="<?php echo osc_esc_html(__('Actions', 'listing-import')); ?>" class="text-end"><a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html(osc_route_admin_url(Sources::EDIT_ROUTE, array('id' => $id))); ?>"><?php _e('Edit', 'listing-import'); ?></a>
                    <?php if ($pull) { ?>
                        <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html(osc_route_admin_url(Sources::PREVIEW_ROUTE, array('id' => $id))); ?>"><?php _e('Preview', 'listing-import'); ?></a>
                        <form method="post" action="<?php echo osc_esc_html($listUrl); ?>" class="d-inline">
                            <input type="hidden" name="li_do" value="fetch">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-dim"><?php _e('Fetch now', 'listing-import'); ?></button>
                        </form>
                    <?php } ?>
                    <button type="button" class="btn btn-sm btn-dim text-danger" onclick="document.getElementById('li-delete-<?php echo $id; ?>').showModal()"><?php _e('Delete', 'listing-import'); ?></button></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php
osc_admin_panel_close();

foreach ($sources as $s) {
    osc_admin_confirm_dialog(array(
        'id'      => 'li-delete-' . (int)$s['pk_i_id'],
        'url'     => $listUrl,
        'fields'  => array('li_do' => 'delete', 'id' => (int)$s['pk_i_id']),
        'title'   => sprintf(__('Delete "%s"?', 'listing-import'), $s['s_name']),
        'text'    => __('Its listings stay on the site, but they are no longer updated from this source.', 'listing-import'),
        'confirm' => __('Delete', 'listing-import'),
    ));
}
