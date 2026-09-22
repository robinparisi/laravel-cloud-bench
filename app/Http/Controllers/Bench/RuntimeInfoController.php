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
        $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        return response()->json([
            'region' => config('bench.region'),
            'environment' => app()->environment(),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'worker_requests' => $timings->requestsServed(),
            'opcache' => is_array($opcache) ? ($opcache['opcache_enabled'] ?? false) : false,
            'jit' => $this->jitStatus(is_array($opcache) ? $opcache : null),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'database' => config("database.connections.{$connection}.driver"),
            'cache_store' => config('cache.default'),
            'session_driver' => config('session.driver'),
        ]);
    }

    /**
     * Without this, /b/cpu is not comparable across environments.
     * "enabled" means configured, "on" means actually running.
     *
     * @param  array<string, mixed>|null  $opcache
     * @return array{enabled: bool, on: bool, mode: string|false, buffer_size: int|null}|null
     */
    private function jitStatus(?array $opcache): ?array
    {
        if (! is_array($opcache['jit'] ?? null)) {
            return null;
        }

        return [
            'enabled' => (bool) ($opcache['jit']['enabled'] ?? false),
            'on' => (bool) ($opcache['jit']['on'] ?? false),
            'mode' => ini_get('opcache.jit'),
            'buffer_size' => $opcache['jit']['buffer_size'] ?? null,
        ];
    }
}
