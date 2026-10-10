<?php
/*
 * This file is part of the Listing Import plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin's endpoints behind core's own API kernel: the routes are accepted below
 * ext/listing-import/, the scopes are declared for admin keys only, and a request is
 * authenticated, scoped, validated and answered the way core does it for its own routes.
 */

require __DIR__ . '/lib/harness.php';
harness_core();
harness_plugin();

use mindstellar\apikey\ApiSettings;
use mindstellar\api\auth\AccessTokens;
use mindstellar\api\auth\MemoisedRows;
use mindstellar\apikey\ApiKeys;
use mindstellar\api\auth\Authenticator;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\CredentialStore;
use mindstellar\api\auth\FailureCounter;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\apikey\StoredKey;
use mindstellar\api\auth\UserRows;
use mindstellar\utility\Clock;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\idempotency\IdempotencyRecord;
use mindstellar\api\idempotency\IdempotencyStore;
use mindstellar\api\Kernel;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\Request;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\listingimport\Api;

if (!function_exists('osc_rewrite_enabled')) {
    function osc_rewrite_enabled()
    {
        return true;
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($withIndex = false)
    {
        return 'https://shop.test/';
    }
}
if (!function_exists('__')) {
    function __($text, $domain = '')
    {
        return $text;
    }
}

/** Core's key table as an array. */
final class KeyRows implements CredentialStore
{
    /** @var array<int,StoredKey> */
    public array $rows = array();

    public function findByTokenId(string $tokenId): ?StoredKey
    {
        foreach ($this->rows as $row) {
            if ($row->tokenId() === $tokenId) {
                return $row;
            }
        }

        return null;
    }

    public function find(int $id): ?StoredKey
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(StoredKey $key): int
    {
        $id = count($this->rows) + 1;
        $this->rows[$id] = new StoredKey(
            $id, $key->kind(), $key->tokenId(), $key->secretHash(), $key->name(), $key->scopes(), $key->owner(),
            $key->rateLimit(), true, $key->expiresAt()
        );

        return $id;
    }

    public function touch(int $id, string $ip, int $time): void
    {
    }

    public function revoke(int $id): bool
    {
        return false;
    }
}

osc_add_filter('api_scopes', static fn ($scopes) => array_merge((array)$scopes, Api::scopes()));

harness_section('the scopes');

$scopes = Scopes::fromHooks();
$admin  = KeyOwner::admin(1);
check('the three scopes are known to core', count(array_intersect(array_keys(Api::scopes()), array_keys($scopes->all()))) === 3);
pin('an admin key may hold all three', array(true, true, true), array_map(
    static fn ($s) => in_array($s, $scopes->allowedFor(CredentialKind::KEY, $admin), true),
    array(Api::WRITE, Api::DELETE, Api::RUNS)
));
pin('a moderator, a user and a public key may hold none', array(false, false, false), array_map(
    static fn ($owner) => count(array_intersect(array_keys(Api::scopes()), $scopes->allowedFor($owner[0], $owner[1]))) > 0,
    array(array(CredentialKind::KEY, KeyOwner::admin(2, true)), array(CredentialKind::KEY, KeyOwner::user(10)), array(CredentialKind::PUBLIC, $admin))
));

harness_section('the routes');

$refused = array();
$api     = fake_api();
foreach (Api::routes(static fn () => $api) as $key => $spec) {
    [$method, $path] = explode(' ', $key, 2);
    osc_api_register_route($method, $path, $spec);
}
$validator = new Validator();
$router    = Router::build($validator, array(), static function (string $message) use (&$refused): void {
    $refused[] = $message;
});
pin('core accepts every route', array(), $refused);
$refused = array();
Router::build(new Validator(\mindstellar\api\schema\Schema::components()), \mindstellar\api\routing\Router::core(), static function (string $message) use (&$refused): void {
    $refused[] = $message;
});
pin('also next to the core routes', array(), $refused);
check('the batch path with a colon is matched', $router->match('POST', 'ext/listing-import/listings:batch') !== null);
check('an external id is matched, a slash in it is not', $router->match('GET', 'ext/listing-import/listings/A-1') !== null
    && $router->match('GET', 'ext/listing-import/listings/a/b') === null);
check('no route outside ext/listing-import/ is registered', $router->match('PUT', 'listings/SKU-1') === null);
check('a run id must be a number', $router->match('GET', 'ext/listing-import/runs/12') !== null
    && $router->match('GET', 'ext/listing-import/runs/abc') === null);

harness_section('a request through the kernel');

$now      = 1_800_000_000;
$clock    = new class ($now) implements Clock {
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
};
$rows     = new KeyRows();
$keys     = new ApiKeys($rows, $scopes, $clock);
$settings = new ApiSettings(true);
$counts   = array();
$nobody   = static fn (): ?array => null;
$kernel   = new Kernel(
    $router,
    new Authenticator($keys, new FailureCounter(static fn () => 0, static fn () => 1), new AccessTokens($scopes, new UserRows($nobody))),
    new RateLimiter(static function (string $b, string $k, int $w) use (&$counts): int {
        return $counts[$b . '|' . $k] = ($counts[$b . '|' . $k] ?? 0) + 1;
    }, $clock),
    $validator,
    $settings,
    new UserRows($nobody),
    new MemoisedRows($nobody),
    new Idempotency(new class () implements IdempotencyStore {
        public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl, string $lock): ?IdempotencyRecord
        {
            return null;
        }

        public function complete(string $hash, string $lock, int $status, string $response): void
        {
        }

        public function release(string $hash, string $lock): void
        {
        }
    }, $clock)
);
$call = static fn (string $method, string $path, string $token = '', ?array $body = null, array $query = array()) => $kernel->handle(new Request(
    $method,
    'v1/ext/listing-import/' . $path,
    $query,
    ($token === '' ? array() : array('Authorization' => 'Bearer ' . $token)) + ($body === null ? array() : array('Content-Type' => 'application/json')),
    '203.0.113.9',
    $body === null ? '' : json_encode($body)
));
$writer = $keys->create(CredentialKind::KEY, 'Partner', array(Api::WRITE, Api::RUNS), $admin);
$reader = $keys->create(CredentialKind::KEY, 'Reader', array(Api::RUNS), $admin);
$GLOBALS['__store']->keySources[$writer->id()] = 1;
$GLOBALS['__store']->keySources[$reader->id()] = 1;
$record = array('external_id' => 'K1', 'title' => 'Blue bike', 'description' => 'A good bike.', 'category' => 'bikes');

pin('no key is 401', 401, $call('POST', 'listings', '', $record)->status());
$r = $call('POST', 'listings', $writer->token(), $record);
pin('a key with the write scope imports: 201 created', array(201, 'created'), array($r->status(), $r->body()['data']['status'] ?? null));
$r = $call('POST', 'listings', $reader->token(), $record);
pin('a key without it is 403 insufficient_scope', array(403, 'insufficient_scope'), array($r->status(), $r->body()['code'] ?? null));
pin('delete needs its own scope', 403, $call('DELETE', 'listings/K1', $writer->token())->status());
pin('the record is read back by its id', 200, $call('GET', 'listings/K1', $writer->token())->status());
$runId = $call('POST', 'listings', $writer->token(), array('external_id' => 'K2') + $record)->body()['data']['run_id'];
pin('a run is read with the runs scope', 200, $call('GET', 'runs/' . $runId, $reader->token())->status());
$r = $call('POST', 'listings', $writer->token(), array('title' => 'No id'));
pin('a record with errors is a 422 with a pointer per field', array(422, 'validation_failed', '/external_id'), array($r->status(), $r->body()['code'], $r->body()['errors'][0]['pointer'] ?? null));
$r = $call('POST', 'listings:batch', $writer->token(), array('records' => array()));
pin('an empty batch is refused by the route\'s own schema', array(422, 'validation_failed'), array($r->status(), $r->body()['code']));
$r = $call('POST', 'listings:batch', $writer->token(), array('records' => array_fill(0, 201, $record)));
pin('201 records is 413', array(413, 'too_large'), array($r->status(), $r->body()['code']));
$r = $call('POST', 'listings:batch', $writer->token(), array('records' => array(array('external_id' => 'B1') + $record)));
pin('a batch is accepted: 202', array(202, 'queued'), array($r->status(), $r->body()['data']['status'] ?? null));
$unlinked = $keys->create(CredentialKind::KEY, 'Unlinked', array(Api::WRITE), $admin);
$r        = $call('POST', 'listings', $unlinked->token(), $record);
pin('a key linked to no source is 409', array(409, 'conflict'), array($r->status(), $r->body()['code']));
pin('the plugin has no ping or spec route of its own: core answers GET openapi.json', 404, $call('GET', 'ping')->status());

exit(harness_result());
