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

namespace mindstellar\listingimport\Auth;

use mindstellar\listingimport\Http\Response;

/**
 * API keys: making them, checking a request's token, and taking them away.
 *
 * A token is `<key id>.<secret>`. The key id is stored as it is, so a key can be found; the
 * secret is stored only as an HMAC under the site's signing key, so a copy of the database
 * does not hand out working tokens. The secret is shown once, when the key is made.
 */
final class KeyStore
{
    public const SCOPE_WRITE  = 'listings:write';
    public const SCOPE_DELETE = 'listings:delete';
    public const SCOPE_RUNS   = 'runs:read';

    /** Every scope a key may hold. `listings:read` is kept free for a later read API. */
    public const SCOPES = array(self::SCOPE_WRITE, self::SCOPE_DELETE, self::SCOPE_RUNS);

    private const KEY_ID_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private KeyRepository $keys;

    private string $pepper;

    /** @var callable(): int */
    private $clock;

    /**
     * @param KeyRepository      $keys
     * @param string             $pepper the site's signing key
     * @param callable|null      $clock  returns the current Unix time; time() by default
     */
    public function __construct(KeyRepository $keys, string $pepper, ?callable $clock = null)
    {
        $this->keys   = $keys;
        $this->pepper = $pepper;
        $this->clock  = $clock ?? 'time';
    }

    /**
     * Make a key.
     *
     * @param string        $name
     * @param array<int,string> $scopes  any of SCOPES; unknown ones are dropped
     * @param int|null      $sourceId the source it imports into, or null for any
     * @param string|null   $expires  'Y-m-d H:i:s', or null for never
     *
     * @return array{id: int, key_id: string, token: string} the token is never shown again
     */
    public function create(string $name, array $scopes, ?int $sourceId = null, ?string $expires = null): array
    {
        $keyId  = $this->newKeyId();
        $secret = bin2hex(random_bytes(32));
        $id     = $this->keys->insert(array(
            'fk_i_source_id' => $sourceId,
            's_name'         => mb_substr(trim($name), 0, 100),
            's_key_id'       => $keyId,
            's_secret_hash'  => $this->hash($secret),
            's_scopes'       => implode(' ', array_values(array_intersect(self::SCOPES, $scopes))),
            'b_enabled'      => 1,
            'dt_expires'     => $expires,
            'dt_created'     => $this->now(),
        ));

        return array('id' => $id, 'key_id' => $keyId, 'token' => $keyId . '.' . $secret);
    }

    /**
     * A new key with the same name, scopes and source. The old one keeps working until it is
     * revoked, so a client can switch over without a gap.
     *
     * @param int $id
     *
     * @return array{id: int, key_id: string, token: string}|null null when there is no such key
     */
    public function rotate(int $id): ?array
    {
        $old = $this->keys->find($id);
        if ($old === null) {
            return null;
        }

        return $this->create(
            (string)$old['s_name'],
            explode(' ', (string)$old['s_scopes']),
            $old['fk_i_source_id'] === null ? null : (int)$old['fk_i_source_id'],
            $old['dt_expires'] === null ? null : (string)$old['dt_expires']
        );
    }

    /**
     * Stop a key working. The row stays, so the log can still name it.
     *
     * @param int $id
     *
     * @return void
     */
    public function revoke(int $id): void
    {
        $this->keys->update($id, array('b_enabled' => 0));
    }

    /**
     * The key a request's Authorization header names, or why it is refused.
     *
     * Every refusal of the token itself answers the same, so a caller cannot tell a key
     * that does not exist from a wrong secret or a revoked key.
     *
     * @param string $authorization the Authorization header
     * @param string $scope         the scope this endpoint needs
     * @param string $ip            the caller's address, stored as the key's last user
     *
     * @return array<string,mixed>|Response the key row, or the answer to send instead
     */
    public function authenticate(string $authorization, string $scope, string $ip)
    {
        if (!preg_match('/^Bearer\s+([0-9A-Za-z]{16})\.([0-9a-f]{64})$/', trim($authorization), $m)) {
            return self::unauthorized();
        }

        $key = $this->keys->findByKeyId($m[1]);
        if ($key === null
            || (int)$key['b_enabled'] !== 1
            || !hash_equals((string)$key['s_secret_hash'], $this->hash($m[2]))
            || ($key['dt_expires'] !== null && strtotime((string)$key['dt_expires']) <= ($this->clock)())
        ) {
            return self::unauthorized();
        }

        if (!in_array($scope, explode(' ', (string)$key['s_scopes']), true)) {
            return Response::error(403, 'forbidden', 'This key may not use this endpoint.');
        }

        $this->keys->update((int)$key['pk_i_id'], array('dt_last_used' => $this->now(), 's_last_ip' => $ip));

        return $key;
    }

    /**
     * Count a request against a key's limit per minute.
     *
     * @param int $id
     * @param int $perMinute
     *
     * @return Response|null the answer to send when the limit is reached, else null
     */
    public function throttle(int $id, int $perMinute): ?Response
    {
        $now    = ($this->clock)();
        $minute = date('Y-m-d H:i:00', $now);
        if ($this->keys->hit($id, $minute) <= $perMinute) {
            return null;
        }
        $response                         = Response::error(429, 'rate_limited', 'Too many requests. Try again shortly.');
        $response->headers['Retry-After'] = (string)(60 - (int)date('s', $now));

        return $response;
    }

    /**
     * @return Response
     */
    public static function unauthorized(): Response
    {
        $response                              = Response::error(401, 'unauthorized', 'A valid API key is required.');
        $response->headers['WWW-Authenticate'] = 'Bearer';

        return $response;
    }

    /**
     * @param string $secret
     *
     * @return string
     */
    private function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->pepper);
    }

    /**
     * @return string
     */
    private function newKeyId(): string
    {
        $id = '';
        for ($i = 0; $i < 16; $i++) {
            $id .= self::KEY_ID_CHARS[random_int(0, strlen(self::KEY_ID_CHARS) - 1)];
        }

        return $id;
    }

    /**
     * @return string
     */
    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }
}
