<?php

namespace App\Providers;

use App\Bench\RequestTimings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class BenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestTimings::class);
    }

    public function boot(): void
    {
        $timings = $this->app->make(RequestTimings::class);

        // Registered once at boot rather than per request: under Octane the
        // connection outlives the request, so a listener added in middleware
        // would stack up and fire once per past request on every query.
        DB::listen(fn (QueryExecuted $query) => $timings->recordQuery($query->time));
    }
}
