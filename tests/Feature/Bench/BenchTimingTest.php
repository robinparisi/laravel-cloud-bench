<?php

use App\Models\BenchRow;

test('reports the application duration in the Server-Timing header', function () {
    $response = $this->get(route('bench.noop'));

    expect(serverTimings($response)['app'])->toBeGreaterThan(0);
});

test('leaves the response body untouched', function () {
    $response = $this->get(route('bench.noop'));

    $response->assertOk();
    expect($response->getContent())->toBe('ok');
});

test('reports the query count accumulated during the request', function () {
    BenchRow::factory()->count(3)->create();

    $response = $this->get(route('bench.db', ['queries' => 3]));

    // The driver-reported duration is rounded to 0.0 for in-memory SQLite, so only the count is asserted.
    expect(serverTimings($response)['queries'])->toBe(3);
});

test('does not accumulate query listeners across requests served by the same process', function () {
    BenchRow::factory()->create();

    $this->get(route('bench.db', ['queries' => 1]));
    $response = $this->get(route('bench.db', ['queries' => 1]));

    expect(serverTimings($response)['queries'])->toBe(1);
});

test('counts the requests served by the process', function () {
    $this->get(route('bench.noop'));
    $response = $this->get(route('bench.noop'));

    expect($response->headers->get('X-Bench-Worker-Requests'))->toBe('2');
});

test('reports the boot duration when the request start time is plausible', function () {
    $response = $this->withServerVariables(['REQUEST_TIME_FLOAT' => microtime(true)])
        ->get(route('bench.noop'));

    expect(serverTimings($response)['boot'])->not->toBeNull();
});

test('omits the boot duration when the request start time predates the request', function () {
    $response = $this->withServerVariables(['REQUEST_TIME_FLOAT' => microtime(true) - 3600])
        ->get(route('bench.noop'));

    expect(serverTimings($response)['boot'])->toBeNull();
});

test('formats durations with a dot under a comma-decimal locale', function () {
    $previous = setlocale(LC_NUMERIC, '0');

    if (setlocale(LC_NUMERIC, 'fr_FR.UTF-8', 'fr_FR', 'de_DE.UTF-8', 'de_DE') === false) {
        $this->markTestSkipped('No comma-decimal locale is installed on this host.');
    }

    try {
        $response = $this->get(route('bench.noop'));

        expect($response->headers->get('Server-Timing'))->toMatch('/app;dur=\d+\.\d+/');
    } finally {
        setlocale(LC_NUMERIC, $previous);
    }
});

test('reports the data centre the request reached the origin through', function () {
    $response = $this->withHeaders(['CF-Ray' => 'a3fc90bdad39d0a7-MAD'])->get(route('bench.noop'));

    expect($response->headers->get('X-Bench-Tier'))->toBe('MAD');
});

test('omits the data centre when the request did not come through an edge', function () {
    $response = $this->get(route('bench.noop'));

    expect($response->headers->has('X-Bench-Tier'))->toBeFalse();
});
