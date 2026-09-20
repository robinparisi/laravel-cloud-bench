<?php

namespace App\Http\Controllers\Bench;

use App\Bench\RequestTimings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The metadata every measurement has to be filed under. Read it once per
 * environment before a run, and again after any redeploy.
 */
class RuntimeInfoController extends Controller
{
    public function __invoke(RequestTimings $timings): JsonResponse
    {
        $connection = config('database.default');

        return response()->json([
            'region' => config('bench.region'),
            'commit' => config('bench.commit'),
            'environment' => app()->environment(),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'worker_requests' => $timings->requestsServed(),
            'opcache' => function_exists('opcache_get_status')
                ? (opcache_get_status(false)['opcache_enabled'] ?? false)
                : false,
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'database' => config("database.connections.{$connection}.driver"),
            'cache_store' => config('cache.default'),
            'session_driver' => config('session.driver'),
        ]);
    }
}
