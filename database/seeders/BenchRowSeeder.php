<?php

namespace Database\Seeders;

use App\Models\BenchRow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class BenchRowSeeder extends Seeder
{
    public function run(): void
    {
        $existing = BenchRow::query()->count();
        $missing = max((int) config('bench.database.rows') - $existing, 0);

        if ($missing === 0) {
            return;
        }

        $now = now();

        collect(range($existing + 1, $existing + $missing))
            ->map(fn (int $number): array => [
                'token' => sprintf('bench-%06d', $number),
                'value' => $number % 10_000,
                'payload' => str_repeat('benchmark payload ', 12),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(500)
            ->each(fn (Collection $chunk) => BenchRow::query()->insert($chunk->all()));
    }
}
