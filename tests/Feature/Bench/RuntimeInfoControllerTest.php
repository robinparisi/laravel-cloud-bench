<?php

test('exposes the metadata every result file is filed under', function () {
    config(['bench.region' => 'eu-west-3']);

    $response = $this->get(route('bench.info'));

    $response->assertOk()
        ->assertJson([
            'region' => 'eu-west-3',
            'environment' => 'testing',
            'sapi' => PHP_SAPI,
            'database' => 'sqlite',
        ])
        ->assertJsonStructure([
            'region', 'environment', 'php', 'sapi', 'worker_requests',
            'opcache', 'jit', 'config_cached', 'routes_cached', 'database',
            'cache_store', 'session_driver',
        ]);
});
