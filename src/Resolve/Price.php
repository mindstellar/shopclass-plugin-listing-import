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
 * Turns a price as a partner writes it into a number.
 *
 * "1.234,50", "1,234.50", "€ 650" and 650 are all prices. When a string has both a comma
 * and a dot, the one written last is the decimal mark. When it has only one of them, it is
 * a decimal mark only if one or two digits follow it: "1.234" is a thousand, "12.5" is not.
 */
final class Price
{
    /**
     * @param int|float|string $amount
     *
     * @return float|null null when nothing numeric is left
     */
    public static function parse($amount): ?float
    {
        if (is_int($amount) || is_float($amount)) {
            return $amount < 0 ? null : (float)$amount;
        }

        // Keep digits and the two marks; a currency sign or code is not part of the number.
        $s = preg_replace('/[^0-9.,]/', '', (string)$amount);
        if ($s === null || $s === '' || !preg_match('/[0-9]/', $s)) {
            return null;
        }

        $comma = strrpos($s, ',');
        $dot   = strrpos($s, '.');
        if ($comma !== false && $dot !== false) {
            $decimal  = $comma > $dot ? ',' : '.';
            $thousand = $decimal === ',' ? '.' : ',';
            $s        = str_replace(array($thousand, $decimal), array('', '.'), $s);
        } elseif ($comma !== false || $dot !== false) {
            $mark  = $comma !== false ? ',' : '.';
            $parts = explode($mark, $s);
            $last  = end($parts);
            $s     = count($parts) === 2 && strlen($last) <= 2
                ? str_replace($mark, '.', $s)
                : str_replace($mark, '', $s);
        }

        return is_numeric($s) ? (float)$s : null;
    }

    /**
     * A price written the way the site reads one back from its own form: the site's decimal
     * mark and no thousands separator.
     *
     * @param float  $price
     * @param string $decimalMark
     *
     * @return string
     */
    public static function forSite(float $price, string $decimalMark): string
    {
        return number_format($price, 2, $decimalMark, '');
    }
}
