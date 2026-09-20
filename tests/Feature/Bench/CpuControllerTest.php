<?php

test('returns the same checksum for the same iteration count', function () {
    $first = $this->get(route('bench.cpu', ['n' => 1000]));
    $second = $this->get(route('bench.cpu', ['n' => 1000]));

    $first->assertOk()->assertJsonPath('iterations', 1000);
    expect($second->json('checksum'))->toBe($first->json('checksum'));
});

test('uses the configured default when no iteration count is given', function () {
    config(['bench.cpu.iterations' => 250]);

    $response = $this->get(route('bench.cpu'));

    $response->assertJsonPath('iterations', 250);
});

test('clamps the iteration count within the configured bounds', function (int $requested, int $expected) {
    config(['bench.cpu.max_iterations' => 500]);

    $response = $this->get(route('bench.cpu', ['n' => $requested]));

    $response->assertJsonPath('iterations', $expected);
})->with([
    'below the floor' => [0, 1],
    'above the cap' => [10_000, 500],
]);
