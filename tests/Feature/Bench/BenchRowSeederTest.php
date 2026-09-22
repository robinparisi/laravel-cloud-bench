<?php

use App\Models\BenchRow;
use Database\Seeders\BenchRowSeeder;

test('tops the table up to the configured row count without duplicating on a second run', function () {
    config(['bench.database.rows' => 5]);
    BenchRow::factory()->count(2)->create();

    $this->seed(BenchRowSeeder::class);
    $this->seed(BenchRowSeeder::class);

    expect(BenchRow::query()->count())->toBe(5);
});

test('seeds rows the database scenario can find by id', function () {
    config(['bench.database.rows' => 3]);

    $this->seed(BenchRowSeeder::class);

    $this->get(route('bench.db', ['queries' => 3]))->assertJson(['queries' => 3, 'found' => 3]);
});
