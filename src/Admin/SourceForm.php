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

use mindstellar\listingimport\Images\AddressGuard;
use mindstellar\listingimport\Import\Source;

/**
 * The source editor, declared: core draws the form, checks CSRF, validates each field and
 * writes the row. The plugin only says what the fields are and which rules join them.
 */
final class SourceForm
{
    public const PAGE_ID = 'listing-import.source';

    public const TABLE = 't_listing_import_source';

    public const FORMATS = array('json', 'ndjson', 'csv', 'shopclass-rss');

    /** The row being edited, or null when one is being added. */
    private static $id = null;

    /**
     * Declare the form for one row. Its options depend on the site's categories, countries,
     * currencies and languages, so it is declared per request.
     *
     * @param int|null $id
     *
     * @return string the page id
     */
    public static function register(?int $id = null): string
    {
        self::$id = $id;
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $none = array('' => __('None', 'listing-import'));

        osc_admin_form(self::PAGE_ID)
            ->title(__('Import source', 'listing-import'))
            ->menu('')
            ->capability('administrator')
            ->store(self::TABLE, 'pk_i_id')
            ->onValidate(static fn (array $values) => self::refuse($values))

            ->group(__('Source', 'listing-import'))
            ->text('s_name', __('Name', 'listing-import'), __('For you only, e.g. the partner\'s name.', 'listing-import'))
                ->required()
                ->set('maxlength', 100)
            ->radio('e_kind', __('How listings arrive', 'listing-import'), array(
                'push' => __('The partner sends them to the API', 'listing-import'),
                'pull' => __('This site fetches a feed on a schedule', 'listing-import'),
            ))
                ->default('push')
            ->checkbox('b_enabled', __('Import from this source', 'listing-import'))
                ->rowLabel(__('On', 'listing-import'))
                ->default(1)

            ->group(__('Feed', 'listing-import'), __('Only for a feed this site fetches.', 'listing-import'))
            ->url('s_url', __('Feed address', 'listing-import'), __('Must be a public http or https address.', 'listing-import'))
                ->dependsOn('e_kind', 'pull')
                ->required()
                ->set('maxlength', 2048)
            ->select('s_format', __('Format', 'listing-import'), array(
                'json'          => __('JSON: a list of records', 'listing-import'),
                'ndjson'        => __('NDJSON: one record per line', 'listing-import'),
                'csv'           => __('CSV: one record per row, a header row naming the fields', 'listing-import'),
                'shopclass-rss' => __('RSS from another Shopclass site', 'listing-import'),
            ))
                ->dependsOn('e_kind', 'pull')
                ->default('json')
            ->textarea('s_mapping', __('Field names', 'listing-import'), __('Only when the feed names its fields differently. One per line: their name = our name, e.g. "Headline = title" or "Town = location.city".', 'listing-import'))
                ->dependsOn('e_kind', 'pull')
                ->set('rows', 4)
            ->number('i_interval_minutes', __('Fetch every (minutes)', 'listing-import'))
                ->dependsOn('e_kind', 'pull')
                ->default(360)
                ->set('min', 60)
                ->set('max', 10080)
            ->select('e_missing', __('When a listing leaves the feed', 'listing-import'), array(
                Source::MISSING_DEACTIVATE => __('Deactivate it (it is never deleted)', 'listing-import'),
                Source::MISSING_KEEP       => __('Keep it as it is', 'listing-import'),
            ))
                ->dependsOn('e_kind', 'pull')
                ->default(Source::MISSING_DEACTIVATE)

            ->group(__('Defaults', 'listing-import'), __('Used when a record does not say.', 'listing-import'))
            ->select('fk_i_category_id', __('Category', 'listing-import'), $none + self::categories())
                ->persist(static fn ($value) => (int)$value)
            ->select('fk_c_country_code', __('Country', 'listing-import'), $none + self::options('SELECT pk_c_code AS k, s_name AS v FROM ' . DB_TABLE_PREFIX . 't_country ORDER BY s_name'))
            ->select('fk_c_currency_code', __('Currency', 'listing-import'), $none + self::options('SELECT pk_c_code AS k, pk_c_code AS v FROM ' . DB_TABLE_PREFIX . 't_currency WHERE b_enabled = 1 ORDER BY pk_c_code'))
            ->select('fk_c_locale_code', __('Language of plain text', 'listing-import'), $none + self::options('SELECT pk_c_code AS k, s_name AS v FROM ' . DB_TABLE_PREFIX . 't_locale WHERE b_enabled = 1 ORDER BY s_name'))
            ->number('fk_i_owner_id', __('Owner (user id)', 'listing-import'), __('Listings with no owner of their own belong to this user. 0 for none.', 'listing-import'))
                ->set('min', 0)
                ->default(0)
            ->text('s_contact_name', __('Contact name', 'listing-import'))
                ->set('maxlength', 100)
            ->email('s_contact_email', __('Contact e-mail', 'listing-import'), __('Shown on listings with no contact of their own. Empty uses the site\'s contact address.', 'listing-import'))
                ->set('maxlength', 100)

            ->group(__('Rules', 'listing-import'))
            ->radio('e_status', __('A new listing', 'listing-import'), array(
                Source::STATUS_SITE    => __('Follows the site\'s moderation setting', 'listing-import'),
                Source::STATUS_ACTIVE  => __('Goes live at once', 'listing-import'),
                Source::STATUS_PENDING => __('Waits for an admin', 'listing-import'),
            ))
                ->default(Source::STATUS_SITE)
            ->checkbox('b_respect_caps', __('Apply the owner\'s listing limit', 'listing-import'))
                ->rowLabel(__('Limits', 'listing-import'))
                ->default(1)

            ->hidden('dt_created')
                ->writeOnly()
                ->persist(static fn () => self::$id === null ? date('Y-m-d H:i:s') : null)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * What the view hands core's form renderer.
     *
     * @param int|null            $id
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    public static function formVars(?int $id, array $values): array
    {
        return array(
            'id'      => self::PAGE_ID,
            'route'   => array(
                'page'   => 'plugins',
                'action' => 'renderplugin',
                'route'  => Sources::EDIT_ROUTE,
                'id'     => $id === null ? '' : (string)$id,
                'li_do'  => 'save',
            ),
            'values'  => $values,
            'actions' => array(
                array('label' => $id === null ? __('Add source', 'listing-import') : __('Save changes', 'listing-import'), 'type' => 'submit', 'variant' => 'primary'),
                array('label' => __('Back to sources', 'listing-import'), 'url' => osc_route_admin_url(Sources::ROUTE), 'variant' => 'dim'),
            ),
        );
    }

    /**
     * The rules no single field can check: a feed address must be one the site may fetch.
     *
     * @param array<string,mixed> $values
     *
     * @return string|null
     */
    private static function refuse(array $values): ?string
    {
        if (($values['e_kind'] ?? '') === 'pull' && (string)($values['s_url'] ?? '') !== '') {
            $check = (new AddressGuard())->check((string)$values['s_url']);
            if (!$check['ok']) {
                return __('Feed address:', 'listing-import') . ' ' . $check['error'];
            }
        }

        return null;
    }

    /**
     * Enabled categories as "Parent › Child", in the site's language.
     *
     * @return array<string,string>
     */
    private static function categories(): array
    {
        $rows = osc_db_select(
            'SELECT c.pk_i_id, c.fk_i_parent_id, d.s_name FROM ' . DB_TABLE_PREFIX . 't_category c'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_category_description d ON d.fk_i_category_id = c.pk_i_id AND d.fk_c_locale_code = ?'
            . ' WHERE c.b_enabled = 1 ORDER BY c.i_position',
            array(osc_current_admin_locale())
        );
        $names = array_column($rows, 's_name', 'pk_i_id');
        $out   = array();
        foreach ($rows as $row) {
            $parent                        = (int)$row['fk_i_parent_id'];
            $out[(string)$row['pk_i_id']] = ($parent && isset($names[$parent]) ? $names[$parent] . ' › ' : '') . $row['s_name'];
        }

        return $out;
    }

    /**
     * @param string $sql selecting k and v
     *
     * @return array<string,string>
     */
    private static function options(string $sql): array
    {
        return array_map('strval', array_column(osc_db_select($sql), 'v', 'k'));
    }
}
