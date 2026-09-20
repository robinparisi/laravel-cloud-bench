<?php

namespace App\Http\Controllers\Bench;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A fixed amount of arithmetic, to measure the vCPU the plan actually gives.
 */
class CpuController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $iterations = min(
            max($request->integer('n', (int) config('bench.cpu.iterations')), 1),
            (int) config('bench.cpu.max_iterations'),
        );

        $checksum = 0.0;

        for ($i = 1; $i <= $iterations; $i++) {
            $checksum += sqrt($i) / ($i % 7 + 1);
        }

        return response()->json([
            'iterations' => $iterations,
            // Proves two environments did the same work before their times get compared.
            'checksum' => round($checksum, 6),
        ]);
    }
}
