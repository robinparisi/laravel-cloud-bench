<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Run Identity
    |--------------------------------------------------------------------------
    |
    | Reported by the /b/info endpoint so a result set can be attributed to the
    | environment and the commit that produced it. Set both per environment.
    |
    */

    'region' => env('BENCH_REGION'),

    'commit' => env('BENCH_COMMIT'),

    /*
    |--------------------------------------------------------------------------
    | Scenario Defaults
    |--------------------------------------------------------------------------
    |
    | The caps keep a public benchmark endpoint from turning into a way to burn
    | the instance down. Keep "rows" in sync with the seeded row count: the
    | database scenario cycles through ids 1..rows to stay deterministic.
    |
    */

    'cpu' => [
        'iterations' => (int) env('BENCH_CPU_ITERATIONS', 200_000),
        'max_iterations' => 5_000_000,
    ],

    'database' => [
        'rows' => (int) env('BENCH_DB_ROWS', 1_000),
        'max_queries' => 200,
    ],

];
