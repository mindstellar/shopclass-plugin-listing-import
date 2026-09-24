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

use mindstellar\listingimport\Admin\Keys;
use mindstellar\listingimport\Auth\DbKeyRepository;
use mindstellar\listingimport\Auth\KeyStore;
use mindstellar\listingimport\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

if (osc_is_moderator()) {
    osc_admin_empty(array(
        'icon'  => 'bi-shield-lock',
        'title' => __('Only an administrator can manage API keys.', 'listing-import'),
    ));

    return;
}

$scopeNames = array(
    KeyStore::SCOPE_WRITE  => __('Add and update listings', 'listing-import'),
    KeyStore::SCOPE_DELETE => __('Remove listings', 'listing-import'),
    KeyStore::SCOPE_RUNS   => __('Read import results', 'listing-import'),
);
$newToken = Keys::takeNewToken();
$keys     = (new DbKeyRepository())->all();
$selfUrl  = osc_route_admin_url(Keys::ROUTE);
$pingUrl  = osc_route_url(Plugin::ROUTE, array('path' => 'ping'));

osc_admin_page_head(__('API keys', 'listing-import'));

if ($newToken !== '') {
    osc_admin_panel_open(__('Your new key', 'listing-import'), array(
        'subtitle' => __('Copy it now. It is not shown again.', 'listing-import'),
    ));
    osc_admin_field(array(
        'type'  => 'text',
        'name'  => 'li_token',
        'id'    => 'li-token',
        'label' => __('Key', 'listing-import'),
        'value' => $newToken,
        'width' => 'full',
        'help'  => __('Send it in the Authorization header: Bearer, a space, then the key.', 'listing-import'),
        'attrs' => array('readonly' => true, 'onfocus' => 'this.select()', 'spellcheck' => 'false'),
    ));
    osc_admin_panel_close();
}

osc_admin_panel_open(__('Keys', 'listing-import'), array(
    'subtitle' => sprintf(__('A partner sends listings to %s with one of these keys.', 'listing-import'), dirname($pingUrl) . '/listings'),
));
?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <th><?php _e('Name', 'listing-import'); ?></th>
            <th><?php _e('Key id', 'listing-import'); ?></th>
            <th><?php _e('Permissions', 'listing-import'); ?></th>
            <th><?php _e('Status', 'listing-import'); ?></th>
            <th><?php _e('Last used', 'listing-import'); ?></th>
            <th class="text-end"><?php _e('Actions', 'listing-import'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($keys === array()) {
            osc_admin_table_empty(6, array(
                'icon'  => 'bi-key',
                'title' => __('No API keys yet', 'listing-import'),
                'text'  => __('Add a key below, then give it to the system that sends you listings.', 'listing-import'),
            ));
        }
        foreach ($keys as $key) {
            $id      = (int)$key['pk_i_id'];
            $enabled = (int)$key['b_enabled'] === 1;
            $expired = $key['dt_expires'] !== null && strtotime((string)$key['dt_expires']) <= time();
            $scopes  = array_map(
                static fn ($scope) => $scopeNames[$scope] ?? $scope,
                array_filter(explode(' ', (string)$key['s_scopes']))
            ); ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Name', 'listing-import')); ?>"><?php echo osc_esc_html($key['s_name']); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Key id', 'listing-import')); ?>"><code><?php echo osc_esc_html($key['s_key_id']); ?></code></td>
                <td data-col-name="<?php echo osc_esc_html(__('Permissions', 'listing-import')); ?>"><?php echo osc_esc_html(implode(', ', $scopes)); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Status', 'listing-import')); ?>"><?php
                    if (!$enabled) {
                        osc_admin_status('inactive', __('Revoked', 'listing-import'));
                    } elseif ($expired) {
                        osc_admin_status('expired', __('Expired', 'listing-import'));
                    } else {
                        osc_admin_status('active', __('Active', 'listing-import'));
                    } ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Last used', 'listing-import')); ?>"><?php echo $key['dt_last_used'] === null
                        ? osc_esc_html(__('Never', 'listing-import'))
                        : osc_esc_html(osc_format_date($key['dt_last_used'])) . '<br><small class="text-muted">' . osc_esc_html($key['s_last_ip']) . '</small>'; ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Actions', 'listing-import')); ?>" class="text-end"><?php if ($enabled) { ?>
                        <form method="post" action="<?php echo osc_esc_html($selfUrl); ?>" class="d-inline">
                            <input type="hidden" name="li_do" value="rotate">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-dim"><?php _e('Rotate', 'listing-import'); ?></button>
                        </form>
                        <button type="button" class="btn btn-sm btn-dim text-danger"
                                onclick="document.getElementById('li-revoke-<?php echo $id; ?>').showModal()">
                            <?php _e('Revoke', 'listing-import'); ?>
                        </button>
                    <?php } ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php
osc_admin_panel_close();

osc_admin_panel_open(__('Add a key', 'listing-import'));
osc_admin_form_open(array(
    'url'    => $selfUrl,
    'fields' => array('li_do' => 'create'),
));
osc_admin_field(array(
    'type'        => 'text',
    'name'        => 'name',
    'label'       => __('Name', 'listing-import'),
    'help'        => __('Who or what uses this key, so you know which to revoke.', 'listing-import'),
    'required'    => true,
    'attrs'       => array('maxlength' => 100),
    'placeholder' => __('e.g. Partner site', 'listing-import'),
));
osc_admin_form_row_open(__('Permissions', 'listing-import'));
foreach ($scopeNames as $scope => $label) {
    osc_admin_checkbox(array(
        'name'    => 'scopes[]',
        'id'      => 'li-scope-' . str_replace(':', '-', $scope),
        'value'   => $scope,
        'checked' => $scope === KeyStore::SCOPE_WRITE,
        'label'   => $label,
    ));
}
osc_admin_form_row_close();
osc_admin_form_close(array(
    array('label' => __('Add key', 'listing-import'), 'type' => 'submit', 'variant' => 'primary'),
));
osc_admin_panel_close();

foreach ($keys as $key) {
    if ((int)$key['b_enabled'] !== 1) {
        continue;
    }
    osc_admin_confirm_dialog(array(
        'id'      => 'li-revoke-' . (int)$key['pk_i_id'],
        'url'     => $selfUrl,
        'fields'  => array('li_do' => 'revoke', 'id' => (int)$key['pk_i_id']),
        'title'   => sprintf(__('Revoke "%s"?', 'listing-import'), $key['s_name']),
        'text'    => __('Every request using this key is refused from now on. This cannot be undone.', 'listing-import'),
        'confirm' => __('Revoke', 'listing-import'),
    ));
}
