<?php

namespace Database\Seeders;

use App\Models\BenchRow;
use Illuminate\Database\Seeder;

class BenchRowSeeder extends Seeder
{
    public function run(): void
    {
        $missing = max((int) config('bench.database.rows') - BenchRow::query()->count(), 0);

        if ($missing === 0) {
            return;
        }

        BenchRow::factory()->count($missing)->create();
    }
}
