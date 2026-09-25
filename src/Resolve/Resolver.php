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

/**
 * Turns a checked record into the fields core's own listing form posts.
 *
 * The importer hands these to core, so core does what it always does with a listing:
 * validation, expiry from the category, location rows, stats. This class only decides what
 * each field means on this site: which category, which place, whose listing, which price.
 * Anything it cannot place falls back to the source's defaults; anything still missing is an
 * error, and anything it had to guess is a warning.
 */
final class Resolver
{
    private Lookups $lookups;

    private Site $site;

    /**
     * @param Lookups $lookups
     * @param Site    $site
     */
    public function __construct(Lookups $lookups, Site $site)
    {
        $this->lookups = $lookups;
        $this->site    = $site;
    }

    /**
     * @param array<string,mixed> $record   a record Validator passed
     * @param array<string,mixed> $defaults the source's defaults: category, country, currency,
     *                                      locale, owner_user_id, contact_name, contact_email
     *
     * @return array{fields: array<string,mixed>, meta: array<int,mixed>, errors: array<string,string>, warnings: array<string,string>}
     */
    public function resolve(array $record, array $defaults = array()): array
    {
        $fields   = array();
        $errors   = array();
        $warnings = array();

        // Text, per locale.
        $locale = in_array($defaults['locale'] ?? '', $this->site->locales, true) ? $defaults['locale'] : $this->site->defaultLocale;
        foreach (array('title', 'description') as $key) {
            $text = is_array($record[$key]) ? $record[$key] : array($locale => $record[$key]);
            foreach (array_keys($text) as $code) {
                if (!in_array($code, $this->site->locales, true)) {
                    $warnings[$key . '.' . $code] = 'This site has no such language; dropped.';
                    unset($text[$code]);
                }
            }
            if ($text === array()) {
                $errors[$key] = 'Nothing left in a language this site uses.';
            }
            $fields[$key] = array_map('strval', $text);
        }

        // Category.
        $category = $this->category($record['category'] ?? null);
        if ($category === null && !empty($defaults['category'])) {
            if (isset($record['category'])) {
                $warnings['category'] = 'No such category here; used the source default.';
            }
            $category = $this->lookups->categoryById((int)$defaults['category']);
        }
        if ($category === null) {
            $errors['category'] = 'No such category here, and the source has no default.';
        }
        $fields['catId'] = $category ?? 0;

        // Price.
        $fields['price']    = '';
        $fields['currency'] = '';
        if (isset($record['price'])) {
            $amount = Price::parse($record['price']['amount'] ?? '');
            $code   = strtoupper((string)($record['price']['currency'] ?? $defaults['currency'] ?? ''));
            if ($amount === null) {
                $errors['price.amount'] = 'Not a price.';
            } elseif ($code === '' || !$this->lookups->currencyEnabled($code)) {
                $errors['price.currency'] = $code === '' ? 'No currency, and the source has no default.' : 'This site does not use ' . $code . '.';
            } else {
                $fields['price']    = Price::forSite($amount, $this->site->decimalMark);
                $fields['currency'] = $code;
            }
        }

        // Owner and contact. The owner goes to core as ownerId, so the contact address never
        // picks an account. A record may only choose an account when its source allows it.
        $mayChoose = !empty($defaults['owners_from_records']);
        $owner     = null;
        if (isset($record['owner']) && !$mayChoose) {
            $warnings['owner'] = 'This source may not choose accounts; ignored.';
        } elseif (isset($record['owner'])) {
            $owner = isset($record['owner']['user_id'])
                ? $this->lookups->userById((int)$record['owner']['user_id'])
                : $this->lookups->userByEmail((string)$record['owner']['email']);
            if ($owner === null) {
                $warnings['owner'] = 'No such user here; the listing is not attached to an account.';
            }
        }
        if ($owner === null && !empty($defaults['owner_user_id'])) {
            $owner = $this->lookups->userById((int)$defaults['owner_user_id']);
        }
        $contact                = is_array($record['contact'] ?? null) ? $record['contact'] : array();
        $fields['contactName']  = $owner['name'] ?? (string)($contact['name'] ?? $defaults['contact_name'] ?? '');
        $fields['contactEmail'] = $owner['email'] ?? (string)($contact['email'] ?? '');
        $fields['ownerId']      = (int)($owner['id'] ?? 0);
        if ($fields['contactEmail'] === '') {
            $fields['contactEmail'] = (string)($defaults['contact_email'] ?? '') ?: $this->site->contactEmail;
        }
        $fields['contactPhone'] = (string)($contact['phone'] ?? '');
        $fields['showEmail']    = !empty($contact['show_email']) ? 1 : 0;

        // Location. A place this site does not have is kept as text, as a form post would be.
        $location = is_array($record['location'] ?? null) ? $record['location'] : array();
        $country  = null;
        if (isset($location['country'])) {
            $country = $this->lookups->country((string)$location['country']);
            if ($country === null) {
                $warnings['location.country'] = 'No such country here.';
            }
        }
        if ($country === null && (string)($defaults['country'] ?? '') !== '') {
            $country = $this->lookups->country((string)$defaults['country']);
        }
        $fields['countryId']    = $country ?? '';
        $fields['country']      = $country === null ? (string)($location['country'] ?? '') : '';
        $fields['regionId']     = '';
        $fields['region']       = (string)($location['region'] ?? '');
        $fields['cityId']       = '';
        $fields['city']         = (string)($location['city'] ?? '');
        if ($country !== null && $fields['region'] !== '') {
            $region = $this->lookups->region($country, $fields['region']);
            if ($region !== null) {
                $fields['regionId'] = (string)$region['id'];
                $fields['region']   = $region['name'];
            } else {
                $warnings['location.region'] = 'No such region here; kept as text.';
            }
        }
        if ($country !== null && $fields['city'] !== '') {
            $city = $this->lookups->city($country, $fields['regionId'] === '' ? null : (int)$fields['regionId'], $fields['city']);
            if ($city !== null) {
                $fields['cityId'] = (string)$city['id'];
                $fields['city']   = $city['name'];
                if ($fields['regionId'] === '') {
                    $fields['regionId'] = (string)$city['region_id'];
                }
            } else {
                $warnings['location.city'] = 'No such city here; kept as text.';
            }
        }
        $fields['cityArea']     = (string)($location['city_area'] ?? '');
        $fields['address']      = (string)($location['address'] ?? '');
        $fields['zip']          = (string)($location['zip'] ?? '');
        $fields['d_coord_lat']  = isset($location['lat']) ? (string)$location['lat'] : '';
        $fields['d_coord_long'] = isset($location['lng']) ? (string)$location['lng'] : '';

        // Expiry: a date in the future, or the category's own rule.
        $fields['dt_expiration'] = '';
        if (isset($record['expires_at'])) {
            $when = strtotime((string)$record['expires_at']);
            if ($when !== false && $when > time()) {
                $fields['dt_expiration'] = date('Y-m-d H:i:s', $when);
            } else {
                $warnings['expires_at'] = 'In the past; the category decides instead.';
            }
        }

        // Custom fields, by slug. Core checks each against the listing's category.
        $meta = array();
        foreach ((array)($record['fields'] ?? array()) as $slug => $value) {
            $id = $this->lookups->fieldBySlug((string)$slug);
            if ($id === null) {
                $warnings['fields.' . $slug] = 'No such field here; dropped.';
                continue;
            }
            if (is_array($value)) {
                $warnings['fields.' . $slug] = 'No field here holds a list; dropped.';
                continue;
            }
            $meta[$id] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return array('fields' => $fields, 'meta' => $meta, 'errors' => $errors, 'warnings' => $warnings);
    }

    /**
     * @param mixed $spec an id (a number, or digits as text), a string, or array with one of id, slug, path, label
     *
     * @return int|null
     */
    private function category($spec): ?int
    {
        if (is_int($spec)) {
            return $this->lookups->categoryById($spec);
        }
        if (is_string($spec)) {
            if (strpos($spec, '>') !== false) {
                return $this->path($spec);
            }
            // A CSV cell is always text, so a number there is still an id.
            $byId = ctype_digit($spec) ? $this->lookups->categoryById((int)$spec) : null;

            return $byId ?? $this->lookups->categoryBySlug($spec) ?? $this->lookups->categoryByName($spec);
        }
        if (!is_array($spec)) {
            return null;
        }
        if (isset($spec['id'])) {
            return $this->lookups->categoryById((int)$spec['id']);
        }
        if (isset($spec['slug'])) {
            return $this->lookups->categoryBySlug((string)$spec['slug']);
        }
        if (isset($spec['path'])) {
            return $this->path((string)$spec['path']);
        }

        return isset($spec['label']) ? $this->lookups->categoryByName((string)$spec['label']) : null;
    }

    /**
     * @param string $path "Vehicles > Bikes"
     *
     * @return int|null
     */
    private function path(string $path): ?int
    {
        $names = array_values(array_filter(array_map('trim', explode('>', $path)), 'strlen'));

        return $names === array() ? null : $this->lookups->categoryByPath($names);
    }
}
