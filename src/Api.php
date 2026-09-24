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

namespace mindstellar\listingimport;

use mindstellar\listingimport\Auth\DbKeyRepository;
use mindstellar\listingimport\Auth\FailureCounter;
use mindstellar\listingimport\Auth\KeyStore;
use mindstellar\listingimport\Http\Request;
use mindstellar\listingimport\Import\Batch;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Import\Importer;
use mindstellar\listingimport\Import\Store;
use mindstellar\listingimport\Http\Response;
use mindstellar\security\SigningKey;

/**
 * The REST API under /api/v1/. Every request arrives on one route hook and is sent to a
 * handler by method and path. Everything but ping needs a key with the right scope.
 */
final class Api
{
    /** method path => [handler, scope]. A path may hold a {id}; a null scope is open to anyone. */
    private const ROUTES = array(
        'GET ping'            => array('ping', null),
        'POST listings'       => array('createListing', KeyStore::SCOPE_WRITE),
        'POST listings:batch' => array('createBatch', KeyStore::SCOPE_WRITE),
        'GET runs/{id}'       => array('showRun', KeyStore::SCOPE_RUNS),
    );

    private KeyStore $keys;

    private FailureCounter $failures;

    private int $perMinute;

    private Importer $importer;

    private Store $store;

    private Batch $batch;

    /**
     * @param KeyStore       $keys
     * @param FailureCounter $failures
     * @param int            $perMinute requests a key may send each minute
     * @param Importer       $importer
     * @param Store          $store
     * @param Batch          $batch
     */
    public function __construct(KeyStore $keys, FailureCounter $failures, int $perMinute, Importer $importer, Store $store, Batch $batch)
    {
        $this->batch     = $batch;
        $this->keys      = $keys;
        $this->failures  = $failures;
        $this->perMinute = max(1, $perMinute);
        $this->importer  = $importer;
        $this->store     = $store;
    }

    /**
     * Answer the current request and stop.
     *
     * @return void
     */
    public static function handle(): void
    {
        $store = new DbStore();
        $api   = new self(
            new KeyStore(new DbKeyRepository(), SigningKey::get()),
            new FailureCounter(),
            (int)(osc_get_preference('rate_limit', Plugin::PAGE) ?: 60),
            Plugin::importer(),
            $store,
            Plugin::batch()
        );
        $api->dispatch(Request::fromGlobals())->send();
    }

    /**
     * The answer for one request.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        [$route, $args] = $this->match($request->method, $request->path);
        if ($route === null) {
            $allowed = $this->methodsFor($request->path);

            return $allowed === array()
                ? Response::error(404, 'not_found', 'No such endpoint.')
                : self::notAllowed($allowed);
        }

        [$handler, $scope] = $route;
        if ($scope !== null) {
            if ($this->failures->exceeded()) {
                return Response::error(429, 'rate_limited', 'Too many failed attempts from this address. Try again later.');
            }
            $key = $this->keys->authenticate($request->authorization, $scope, $request->ip);
            if ($key instanceof Response) {
                if ($key->status === 401) {
                    $this->failures->record();
                }

                return $key;
            }
            $limited = $this->keys->throttle((int)$key['pk_i_id'], $this->perMinute);
            if ($limited !== null) {
                return $limited;
            }
        }

        return $this->$handler($request, $key ?? null, $args);
    }

    /**
     * @return Response
     */
    private function ping(Request $request, ?array $key): Response
    {
        return Response::ok(array('plugin' => 'listing-import', 'version' => Plugin::VERSION));
    }

    /**
     * Import one record into the key's source.
     *
     * @param Request             $request
     * @param array<string,mixed> $key the key the request was made with
     *
     * @return Response
     */
    private function createListing(Request $request, array $key): Response
    {
        $record = $request->json();
        if ($record instanceof Response) {
            return $record;
        }
        $source = $this->source($key);
        if ($source instanceof Response) {
            return $source;
        }

        $runId  = $this->store->startRun($source->id, 'push', false);
        $result = $this->importer->import($source, $record, $runId);
        $this->store->finishRun($runId, array($result['status'] => 1), 'Key ' . $key['s_key_id']);

        if ($result['status'] === Importer::FAILED) {
            $response                          = Response::error(422, 'not_imported', 'The record was not imported; see fields.');
            $response->body['error']['fields'] = $result['errors'];
        } else {
            $response = Response::ok(array(
                'status'      => $result['status'],
                'external_id' => $result['external_id'],
                'item_id'     => $result['item_id'],
                'run_id'      => $runId,
            ), $result['status'] === Importer::CREATED ? 201 : 200);
        }
        if ($result['warnings'] !== array()) {
            $response->body['warnings'] = $result['warnings'];
        }

        return $response;
    }

    /**
     * Start a run for up to MAX_RECORDS records and queue them. The answer is at once; the
     * run's page says how it went.
     *
     * @param Request             $request
     * @param array<string,mixed> $key
     *
     * @return Response
     */
    private function createBatch(Request $request, array $key): Response
    {
        $body = $request->json();
        if ($body instanceof Response) {
            return $body;
        }
        $records = $body['records'] ?? null;
        if (!is_array($records) || $records === array() || array_keys($records) !== range(0, count($records) - 1)) {
            return Response::error(422, 'invalid_batch', 'Send {"records": [...]} with at least one record.');
        }
        if (count($records) > Batch::MAX_RECORDS) {
            return Response::error(413, 'too_many_records', 'At most ' . Batch::MAX_RECORDS . ' records per batch.');
        }
        $source = $this->source($key);
        if ($source instanceof Response) {
            return $source;
        }
        $runId = $this->batch->queue($source, $records, 'push');

        return Response::ok(array('run_id' => $runId, 'records' => count($records), 'status' => 'queued'), 202);
    }

    /**
     * How a run went. A key sees only runs of its own source.
     *
     * @param Request             $request
     * @param array<string,mixed> $key
     * @param array<int,string>   $args the run id
     *
     * @return Response
     */
    private function showRun(Request $request, array $key, array $args): Response
    {
        $run    = $this->store->run((int)$args[0]);
        $source = $this->source($key);
        if ($run === null || $source instanceof Response || (int)$run['fk_i_source_id'] !== $source->id) {
            return Response::error(404, 'not_found', 'No such run.');
        }
        $counts = array();
        foreach (array('created', 'updated', 'unchanged', 'retired', 'failed') as $name) {
            $counts[$name] = (int)$run['i_' . $name];
        }

        return Response::ok(array(
            'run_id'      => (int)$run['pk_i_id'],
            'status'      => $run['dt_finished'] === null ? 'running' : 'finished',
            'records'     => (int)$run['i_total'],
            'counts'      => $counts,
            'started_at'  => (string)$run['dt_started'],
            'finished_at' => $run['dt_finished'] === null ? null : (string)$run['dt_finished'],
            'failures'    => $this->store->runErrors((int)$run['pk_i_id'], 50),
        ));
    }

    /**
     * The source a key imports into.
     *
     * @param array<string,mixed> $key
     *
     * @return \mindstellar\listingimport\Import\Source|Response
     */
    private function source(array $key)
    {
        $source = $this->store->source($key['fk_i_source_id'] === null ? null : (int)$key['fk_i_source_id']);

        return $source ?? Response::error(409, 'no_source', 'This key imports into a source that is missing or switched off.');
    }

    /**
     * The route for a method and path, and what its {id} matched.
     *
     * @param string $method
     * @param string $path
     *
     * @return array{0: array{0: string, 1: ?string}|null, 1: array<int,string>}
     */
    private function match(string $method, string $path): array
    {
        foreach (self::ROUTES as $route => $spec) {
            [$routeMethod, $pattern] = explode(' ', $route, 2);
            if ($routeMethod === $method && preg_match(self::regex($pattern), $path, $m)) {
                return array($spec, array_slice($m, 1));
            }
        }

        return array(null, array());
    }

    /**
     * A route's path as a regex; {id} matches a number.
     *
     * @param string $pattern
     *
     * @return string
     */
    private static function regex(string $pattern): string
    {
        return '#^' . str_replace('\\{id\\}', '([0-9]+)', preg_quote($pattern, '#')) . '$#';
    }

    /**
     * @param string $path
     *
     * @return array<int,string> the methods this path answers
     */
    private function methodsFor(string $path): array
    {
        $methods = array();
        foreach (array_keys(self::ROUTES) as $route) {
            [$method, $pattern] = explode(' ', $route, 2);
            if (preg_match(self::regex($pattern), $path)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @param array<int,string> $allowed
     *
     * @return Response
     */
    private static function notAllowed(array $allowed): Response
    {
        $list                       = implode(', ', $allowed);
        $response                   = Response::error(405, 'method_not_allowed', 'Use ' . $list . ' for this endpoint.');
        $response->headers['Allow'] = $list;

        return $response;
    }
}
