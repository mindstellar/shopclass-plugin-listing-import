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
use mindstellar\listingimport\Images\AddressGuard;
use mindstellar\listingimport\Images\CurlTransport;
use mindstellar\listingimport\Images\Fetcher;
use mindstellar\listingimport\Import\CoreListings;
use mindstellar\listingimport\Import\DbStore;
use mindstellar\listingimport\Import\Importer;
use mindstellar\listingimport\Import\Store;
use mindstellar\listingimport\Resolve\DbLookups;
use mindstellar\listingimport\Resolve\Resolver;
use mindstellar\listingimport\Resolve\Site;
use mindstellar\listingimport\Http\Response;
use mindstellar\security\SigningKey;

/**
 * The REST API under /api/v1/. Every request arrives on one route hook and is sent to a
 * handler by method and path. Everything but ping needs a key with the right scope.
 */
final class Api
{
    /** method path => [handler, scope]. A null scope is open to anyone. */
    private const ROUTES = array(
        'GET ping'      => array('ping', null),
        'POST listings' => array('createListing', KeyStore::SCOPE_WRITE),
    );

    private KeyStore $keys;

    private FailureCounter $failures;

    private int $perMinute;

    private Importer $importer;

    private Store $store;

    /**
     * @param KeyStore       $keys
     * @param FailureCounter $failures
     * @param int            $perMinute requests a key may send each minute
     * @param Importer       $importer
     * @param Store          $store
     */
    public function __construct(KeyStore $keys, FailureCounter $failures, int $perMinute, Importer $importer, Store $store)
    {
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
            new Importer(
                new Resolver(new DbLookups(), Site::current()),
                new CoreListings(),
                $store,
                new Fetcher(new AddressGuard(), new CurlTransport(10), Plugin::tempDir())
            ),
            $store
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
        $route = self::ROUTES[$request->method . ' ' . $request->path] ?? null;
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

        return $this->$handler($request, $key ?? null);
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
        $source = $this->store->source($key['fk_i_source_id'] === null ? null : (int)$key['fk_i_source_id']);
        if ($source === null) {
            return Response::error(409, 'no_source', 'This key imports into a source that is missing or switched off.');
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
     * @param string $path
     *
     * @return array<int,string> the methods this path answers
     */
    private function methodsFor(string $path): array
    {
        $methods = array();
        foreach (array_keys(self::ROUTES) as $route) {
            [$method, $routePath] = explode(' ', $route, 2);
            if ($routePath === $path) {
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
