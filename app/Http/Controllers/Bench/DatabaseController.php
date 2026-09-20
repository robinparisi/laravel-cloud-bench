<?php

namespace App\Http\Controllers\Bench;

use App\Bench\QueryMode;
use App\Http\Controllers\Controller;
use App\Models\BenchRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sequential point lookups. One query measures app-to-database latency; many
 * queries multiply it, which is what exposes a cross-region database.
 */
class DatabaseController extends Controller
{
    public function __invoke(Request $request, int $queries): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', Rule::enum(QueryMode::class)],
        ]);

        $mode = QueryMode::tryFrom($validated['mode'] ?? '') ?? QueryMode::Eloquent;

        $queries = min(max($queries, 1), (int) config('bench.database.max_queries'));
        $rows = max((int) config('bench.database.rows'), 1);

        $found = 0;

        for ($i = 0; $i < $queries; $i++) {
            // Deterministic ids: a random draw would add its own variance to the measurement.
            $id = $i % $rows + 1;

            $row = $mode === QueryMode::Raw
                ? DB::selectOne('select id, token from bench_rows where id = ?', [$id])
                : BenchRow::query()->find($id);

            if ($row !== null) {
                $found++;
            }
        }

        return response()->json([
            'mode' => $mode->value,
            'queries' => $queries,
            // A found count below the query count means the table was never seeded.
            'found' => $found,
        ]);
    }
}
