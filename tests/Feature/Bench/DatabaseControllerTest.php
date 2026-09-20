<?php

use App\Models\BenchRow;

test('runs one query per requested lookup', function () {
    BenchRow::factory()->count(5)->create();

    $response = $this->get(route('bench.db', ['queries' => 5]));

    $response->assertJson(['mode' => 'eloquent', 'queries' => 5, 'found' => 5]);
    expect(serverTimings($response)['queries'])->toBe(5);
});

test('runs one query per requested lookup in raw mode', function () {
    BenchRow::factory()->count(2)->create();

    $response = $this->get(route('bench.db', ['queries' => 2, 'mode' => 'raw']));

    $response->assertJson(['mode' => 'raw', 'queries' => 2, 'found' => 2]);
    expect(serverTimings($response)['queries'])->toBe(2);
});

test('reports fewer rows found than queries run when the table is not seeded', function () {
    BenchRow::factory()->count(2)->create();

    $response = $this->get(route('bench.db', ['queries' => 4]));

    $response->assertJson(['queries' => 4, 'found' => 2]);
});

test('returns 422 when the query mode is unknown', function () {
    $response = $this->get(route('bench.db', ['queries' => 1, 'mode' => 'graphql']));

    $response->assertUnprocessable()->assertInvalid('mode');
});

test('caps the query count at the configured maximum', function () {
    config(['bench.database.max_queries' => 3]);
    BenchRow::factory()->count(3)->create();

    $response = $this->get(route('bench.db', ['queries' => 50]));

    $response->assertJsonPath('queries', 3);
});
