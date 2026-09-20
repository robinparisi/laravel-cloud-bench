<?php

namespace App\Bench;

/**
 * Per-request accumulator for the benchmark timings.
 *
 * Bound as a singleton so the query listener can be registered once at boot.
 * Under Octane the instance outlives the request, so reset() must run at the
 * start of every request. The surviving request counter is deliberate: it is
 * how a run tells a warm worker apart from a cold one.
 */
class RequestTimings
{
    private ?float $startedAt = null;

    private float $databaseMs = 0.0;

    private int $queryCount = 0;

    private int $requestsServed = 0;

    public function reset(): void
    {
        $this->startedAt = hrtime(true);
        $this->databaseMs = 0.0;
        $this->queryCount = 0;
        $this->requestsServed++;
    }

    public function recordQuery(float $milliseconds): void
    {
        $this->databaseMs += $milliseconds;
        $this->queryCount++;
    }

    public function elapsedMs(): float
    {
        if ($this->startedAt === null) {
            return 0.0;
        }

        return (hrtime(true) - $this->startedAt) / 1e6;
    }

    public function databaseMs(): float
    {
        return $this->databaseMs;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Requests handled by this PHP process. Always 1 under php-fpm; under
     * Octane it grows, which is what identifies a reused worker.
     */
    public function requestsServed(): int
    {
        return $this->requestsServed;
    }
}
