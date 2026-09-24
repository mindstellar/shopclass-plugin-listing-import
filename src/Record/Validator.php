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
 * Checks one listing record against the v1 format before anything is imported.
 *
 * It checks shape only: whether a category or a place exists is the importer's question.
 * Every problem is reported at once, each under the path of the field it belongs to, so a
 * client fixes a record in one round trip. Unknown keys are warnings, not errors, so a newer
 * client can send fields an older site ignores.
 */
final class Validator
{
    public const MAX_IMAGES = 20;

    /** Top-level keys and what each must be. */
    private const KEYS = array(
        'external_id'  => 'id',
        'title'        => 'text',
        'description'  => 'text',
        'price'        => 'price',
        'category'     => 'category',
        'location'     => 'location',
        'contact'      => 'contact',
        'owner'        => 'owner',
        'images'       => 'images',
        'fields'       => 'fields',
        'expires_at'   => 'date',
        'published_at' => 'date',
        'source_url'   => 'url',
    );

    private const LOCATION_KEYS = array('country', 'region', 'city', 'city_area', 'address', 'zip', 'lat', 'lng');

    /** @var array<string,string> */
    private array $errors = array();

    /** @var array<string,string> */
    private array $warnings = array();

    /**
     * @param array<mixed> $record
     *
     * @return array{errors: array<string,string>, warnings: array<string,string>}
     */
    public static function check(array $record): array
    {
        $v = new self();
        $v->record($record);

        return array('errors' => $v->errors, 'warnings' => $v->warnings);
    }

    /**
     * @param array<mixed> $r
     *
     * @return void
     */
    private function record(array $r): void
    {
        // A category may come from the source's defaults, so the resolver decides whether one is missing.
        foreach (array('external_id', 'title', 'description') as $required) {
            if (!array_key_exists($required, $r) || $r[$required] === null || $r[$required] === '') {
                $this->errors[$required] = 'Required.';
            }
        }

        foreach ($r as $key => $value) {
            if (!isset(self::KEYS[$key])) {
                $this->warnings[(string)$key] = 'Unknown field, ignored.';
                continue;
            }
            if ($value === null || isset($this->errors[$key])) {
                continue;
            }
            $this->{self::KEYS[$key]}($key, $value);
        }
    }

    private function id(string $path, $v): void
    {
        if (!is_string($v) && !is_int($v)) {
            $this->errors[$path] = 'Must be a string.';
        } elseif (strlen((string)$v) > 191) {
            $this->errors[$path] = 'At most 191 characters.';
        }
    }

    /** A string, or a map of locale code to string. */
    private function text(string $path, $v): void
    {
        if (is_string($v)) {
            return;
        }
        if (!is_array($v) || $v === array() || self::isList($v)) {
            $this->errors[$path] = 'Must be a string, or an object of locale code to string.';

            return;
        }
        foreach ($v as $locale => $text) {
            if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', (string)$locale)) {
                $this->errors[$path . '.' . $locale] = 'Not a locale code such as en_US.';
            } elseif (!is_string($text)) {
                $this->errors[$path . '.' . $locale] = 'Must be a string.';
            }
        }
    }

    private function price(string $path, $v): void
    {
        if (!is_array($v)) {
            $this->errors[$path] = 'Must be an object with amount and currency.';

            return;
        }
        $amount = $v['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount) && !(is_string($amount) && $amount !== '')) {
            $this->errors[$path . '.amount'] = 'Required: a number, or a price string such as "1.234,50".';
        } elseif ((is_int($amount) || is_float($amount)) && $amount < 0) {
            $this->errors[$path . '.amount'] = 'Cannot be negative.';
        }
        if (isset($v['currency']) && (!is_string($v['currency']) || !preg_match('/^[A-Z]{3}$/', $v['currency']))) {
            $this->errors[$path . '.currency'] = 'Must be a three-letter code such as EUR.';
        }
        $this->unknown($path, $v, array('amount', 'currency'));
    }

    /** By id, slug, path ("Cars > Vans") or label; exactly one. */
    private function category(string $path, $v): void
    {
        if (is_int($v)) {
            return;
        }
        if (is_string($v)) {
            return;
        }
        if (!is_array($v)) {
            $this->errors[$path] = 'Must be an id, a string, or an object with one of id, slug, path or label.';

            return;
        }
        $given = array_intersect(array_keys($v), array('id', 'slug', 'path', 'label'));
        if (count($given) !== 1) {
            $this->errors[$path] = 'Give exactly one of id, slug, path or label.';
        }
        $this->unknown($path, $v, array('id', 'slug', 'path', 'label'));
    }

    private function location(string $path, $v): void
    {
        if (!is_array($v)) {
            $this->errors[$path] = 'Must be an object.';

            return;
        }
        foreach (array('lat' => 90, 'lng' => 180) as $axis => $limit) {
            if (isset($v[$axis]) && (!is_numeric($v[$axis]) || abs((float)$v[$axis]) > $limit)) {
                $this->errors[$path . '.' . $axis] = 'Must be a number between -' . $limit . ' and ' . $limit . '.';
            }
        }
        if (isset($v['country']) && (!is_string($v['country']) || $v['country'] === '')) {
            $this->errors[$path . '.country'] = 'Must be a country code or name.';
        }
        $this->unknown($path, $v, self::LOCATION_KEYS);
    }

    private function contact(string $path, $v): void
    {
        if (!is_array($v)) {
            $this->errors[$path] = 'Must be an object.';

            return;
        }
        if (isset($v['email']) && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$path . '.email'] = 'Not an e-mail address.';
        }
        if (isset($v['show_email']) && !is_bool($v['show_email'])) {
            $this->errors[$path . '.show_email'] = 'Must be true or false.';
        }
        $this->unknown($path, $v, array('name', 'email', 'phone', 'show_email'));
    }

    private function owner(string $path, $v): void
    {
        if (!is_array($v) || count(array_intersect(array_keys($v), array('user_id', 'email'))) !== 1) {
            $this->errors[$path] = 'Must be an object with one of user_id or email.';

            return;
        }
        if (isset($v['email']) && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$path . '.email'] = 'Not an e-mail address.';
        }
        if (isset($v['user_id']) && (!is_int($v['user_id']) || $v['user_id'] < 1)) {
            $this->errors[$path . '.user_id'] = 'Must be a positive whole number.';
        }
        $this->unknown($path, $v, array('user_id', 'email'));
    }

    private function images(string $path, $v): void
    {
        if (!is_array($v) || !self::isList($v)) {
            $this->errors[$path] = 'Must be a list of image addresses.';

            return;
        }
        if (count($v) > self::MAX_IMAGES) {
            $this->errors[$path] = 'At most ' . self::MAX_IMAGES . ' images.';
        }
        foreach ($v as $i => $url) {
            if (!is_string($url) || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->errors[$path . '.' . $i] = 'Must be an http or https address.';
            }
        }
    }

    /** Custom fields by slug; a value is a string, a number, a boolean or a list of strings. */
    private function fields(string $path, $v): void
    {
        if (!is_array($v) || ($v !== array() && self::isList($v))) {
            $this->errors[$path] = 'Must be an object of field slug to value.';

            return;
        }
        foreach ($v as $slug => $value) {
            $ok = is_scalar($value) || (is_array($value) && self::isList($value)
                && count(array_filter($value, 'is_string')) === count($value));
            if (!$ok) {
                $this->errors[$path . '.' . $slug] = 'Must be a string, number, true/false or a list of strings.';
            }
        }
    }

    private function date(string $path, $v): void
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?/', $v) || strtotime($v) === false) {
            $this->errors[$path] = 'Must be a date such as 2026-10-01 or 2026-10-01T12:00:00Z.';
        }
    }

    private function url(string $path, $v): void
    {
        if (!is_string($v) || !preg_match('#^https?://#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) {
            $this->errors[$path] = 'Must be an http or https address.';
        }
    }

    /**
     * Whether an array is a plain list. array_is_list() arrived in PHP 8.1; this plugin
     * supports 8.0.
     *
     * @param array<mixed> $a
     *
     * @return bool
     */
    public static function isList(array $a): bool
    {
        return $a === array() || array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * @param string            $path
     * @param array<mixed>      $v
     * @param array<int,string> $known
     *
     * @return void
     */
    private function unknown(string $path, array $v, array $known): void
    {
        foreach (array_diff(array_keys($v), $known) as $key) {
            $this->warnings[$path . '.' . $key] = 'Unknown field, ignored.';
        }
    }
}

