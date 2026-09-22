<?php

test('holds the response for the requested duration', function () {
    $response = $this->get(route('bench.sleep', ['ms' => 20]));

    $response->assertOk()->assertJsonPath('slept_ms', 20);
    expect(serverTimings($response)['app'])->toBeGreaterThanOrEqual(20);
});

test('returns immediately when no duration is given', function () {
    $this->get(route('bench.sleep'))->assertJsonPath('slept_ms', 0);
});

test('clamps the duration within the configured bounds', function (int $requested, int $expected) {
    config(['bench.sleep.max_ms' => 50]);

    $this->get(route('bench.sleep', ['ms' => $requested]))->assertJsonPath('slept_ms', $expected);
})->with([
    'below the floor' => [-10, 0],
    'above the cap' => [5_000, 50],
]);
