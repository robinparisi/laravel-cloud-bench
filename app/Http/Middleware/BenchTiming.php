<?php

namespace App\Http\Middleware;

use App\Bench\RequestTimings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports server-side timings through the Server-Timing header, so a load
 * generator can separate application time from network time.
 *
 * Timings are never merged into the response body: re-encoding the payload
 * would add work after the measurement and distort the figure being taken.
 */
class BenchTiming
{
    /**
     * Anything above this is an uptime reading rather than a boot time.
     */
    private const MAX_PLAUSIBLE_BOOT_MS = 60_000;

    public function __construct(private RequestTimings $timings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->timings->reset();

        $response = $next($request);

        $appMs = $this->timings->elapsedMs();

        $metrics = [
            'app;dur='.$this->duration($appMs),
            'db;dur='.$this->duration($this->timings->databaseMs()).';desc="'.$this->timings->queryCount().' queries"',
        ];

        $bootMs = $this->bootMs($request, $appMs);

        if ($bootMs !== null) {
            $metrics[] = 'boot;dur='.$this->duration($bootMs);
        }

        $response->headers->set('Server-Timing', implode(', ', $metrics));
        $response->headers->set('X-Bench-Worker-Requests', (string) $this->timings->requestsServed());

        $tier = $this->upperTier($request);

        if ($tier !== null) {
            $response->headers->set('X-Bench-Tier', $tier);
        }

        return $response;
    }

    /**
     * The data centre the request reached the origin through, from the CF-Ray
     * the origin received.
     *
     * The client sees the one it entered through, which is not the same: a
     * request can be forwarded via another data centre on the way in, and that
     * detour lands in the client's time without appearing anywhere else.
     */
    private function upperTier(Request $request): ?string
    {
        $ray = $request->header('CF-Ray');

        if (! is_string($ray) || ! str_contains($ray, '-')) {
            return null;
        }

        return Str::afterLast($ray, '-');
    }

    /**
     * Server-Timing requires a dot as the decimal separator. sprintf('%f') and
     * (string) casts follow LC_NUMERIC, so on a host whose locale uses a comma
     * the header parses as a truncated integer instead of failing loudly.
     */
    private function duration(float $milliseconds): string
    {
        return number_format($milliseconds, 3, '.', '');
    }

    /**
     * Time spent before this middleware ran: SAPI startup plus framework boot.
     *
     * REQUEST_TIME_FLOAT is refreshed per request under php-fpm, but an Octane
     * runtime may leave the worker's boot value in place, which would report
     * the worker uptime instead. An implausible value is dropped rather than
     * published as a boot time, so compare `boot` against the client TTFB on
     * every new runtime before trusting it.
     */
    private function bootMs(Request $request, float $appMs): ?float
    {
        $requestTime = $request->server('REQUEST_TIME_FLOAT');

        if (! is_numeric($requestTime)) {
            return null;
        }

        $bootMs = (microtime(true) - (float) $requestTime) * 1000 - $appMs;

        if ($bootMs < 0 || $bootMs > self::MAX_PLAUSIBLE_BOOT_MS) {
            return null;
        }

        return $bootMs;
    }
}
