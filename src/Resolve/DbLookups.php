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

namespace mindstellar\listingimport\Resolve;

use mindstellar\location\LocationImporter;

/**
 * Lookups against the site's tables. Names are compared the way the location import
 * compares them: "Villeneuve-d'Ascq" and "villeneuve d ascq" are one place.
 */
final class DbLookups implements Lookups
{
    /** @var array<int,array{id:int,parent:int,names:array<int,string>}>|null every enabled category */
    private ?array $categories = null;

    private function t(string $table): string
    {
        return DB_TABLE_PREFIX . $table;
    }

    private static function key(string $value): string
    {
        return LocationImporter::normalizeKey($value);
    }

    public function categoryById(int $id): ?int
    {
        return isset($this->categories()[$id]) ? $id : null;
    }

    public function categoryBySlug(string $slug): ?int
    {
        $id = osc_db_scalar(
            'SELECT d.fk_i_category_id FROM ' . $this->t('t_category_description') . ' d'
            . ' JOIN ' . $this->t('t_category') . ' c ON c.pk_i_id = d.fk_i_category_id'
            . ' WHERE d.s_slug = ? AND c.b_enabled = 1 LIMIT 1',
            array(trim($slug))
        );

        return $id === null ? null : (int)$id;
    }

    public function categoryByPath(array $names): ?int
    {
        $parent = 0;
        $found  = null;
        foreach ($names as $name) {
            $found = null;
            foreach ($this->categories() as $category) {
                if ($category['parent'] === $parent && in_array(self::key($name), $category['names'], true)) {
                    $found = $category['id'];
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $parent = $found;
        }

        return $found;
    }

    public function categoryByName(string $name): ?int
    {
        $matches = array();
        foreach ($this->categories() as $category) {
            if (in_array(self::key($name), $category['names'], true)) {
                $matches[] = $category['id'];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function currencyEnabled(string $code): bool
    {
        return osc_db_scalar(
            'SELECT 1 FROM ' . $this->t('t_currency') . ' WHERE pk_c_code = ? AND b_enabled = 1',
            array(strtoupper($code))
        ) !== null;
    }

    public function userById(int $id): ?array
    {
        return $this->user('pk_i_id = ?', $id);
    }

    public function userByEmail(string $email): ?array
    {
        return $this->user('s_email = ?', trim($email));
    }

    public function country(string $codeOrName): ?string
    {
        $value = trim($codeOrName);
        if (preg_match('/^[A-Za-z]{2}$/', $value)) {
            $code = osc_db_scalar('SELECT pk_c_code FROM ' . $this->t('t_country') . ' WHERE pk_c_code = ?', array(strtoupper($value)));
            if ($code !== null) {
                return (string)$code;
            }
        }
        foreach (osc_db_select('SELECT pk_c_code, s_name FROM ' . $this->t('t_country')) as $row) {
            if (self::key((string)$row['s_name']) === self::key($value)) {
                return (string)$row['pk_c_code'];
            }
        }

        return null;
    }

    public function region(string $countryCode, string $name): ?array
    {
        foreach (osc_db_select(
            'SELECT pk_i_id, s_name FROM ' . $this->t('t_region') . ' WHERE fk_c_country_code = ?',
            array($countryCode)
        ) as $row) {
            if (self::key((string)$row['s_name']) === self::key($name)) {
                return array('id' => (int)$row['pk_i_id'], 'name' => (string)$row['s_name']);
            }
        }

        return null;
    }

    public function city(string $countryCode, ?int $regionId, string $name): ?array
    {
        $sql    = 'SELECT pk_i_id, s_name, fk_i_region_id FROM ' . $this->t('t_city') . ' WHERE fk_c_country_code = ?';
        $params = array($countryCode);
        if ($regionId !== null) {
            $sql     .= ' AND fk_i_region_id = ?';
            $params[] = $regionId;
        }
        // Narrow in SQL first: the first letter of a city's name, in any case.
        $sql     .= ' AND s_name LIKE ?';
        $params[] = mb_substr(trim($name), 0, 1) . '%';
        $matches  = array();
        foreach (osc_db_select($sql, $params) as $row) {
            if (self::key((string)$row['s_name']) === self::key($name)) {
                $matches[] = array('id' => (int)$row['pk_i_id'], 'name' => (string)$row['s_name'], 'region_id' => (int)$row['fk_i_region_id']);
            }
        }

        // Without a region, a name shared by two cities names neither.
        return count($matches) === 1 || ($regionId !== null && $matches !== array()) ? $matches[0] : null;
    }

    public function fieldBySlug(string $slug): ?int
    {
        $id = osc_db_scalar('SELECT pk_i_id FROM ' . $this->t('t_meta_fields') . ' WHERE s_slug = ?', array(trim($slug)));

        return $id === null ? null : (int)$id;
    }

    /**
     * @param string $where
     * @param mixed  $value
     *
     * @return array{id: int, name: string, email: string}|null
     */
    private function user(string $where, $value): ?array
    {
        $row = osc_db_select_one('SELECT pk_i_id, s_name, s_email FROM ' . $this->t('t_user') . ' WHERE ' . $where, array($value));

        return $row === null ? null : array('id' => (int)$row['pk_i_id'], 'name' => (string)$row['s_name'], 'email' => (string)$row['s_email']);
    }

    /**
     * Every enabled category with its names in every language, loaded once per run.
     *
     * @return array<int,array{id:int,parent:int,names:array<int,string>}>
     */
    private function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }
        $this->categories = array();
        foreach (osc_db_select(
            'SELECT c.pk_i_id, c.fk_i_parent_id, d.s_name FROM ' . $this->t('t_category') . ' c'
            . ' LEFT JOIN ' . $this->t('t_category_description') . ' d ON d.fk_i_category_id = c.pk_i_id'
            . ' WHERE c.b_enabled = 1'
        ) as $row) {
            $id = (int)$row['pk_i_id'];
            if (!isset($this->categories[$id])) {
                $this->categories[$id] = array('id' => $id, 'parent' => (int)$row['fk_i_parent_id'], 'names' => array());
            }
            if ((string)$row['s_name'] !== '') {
                $this->categories[$id]['names'][] = self::key((string)$row['s_name']);
            }
        }

        return $this->categories;
    }
}
