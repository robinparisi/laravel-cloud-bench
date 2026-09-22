<?php

namespace App\Http\Controllers\Bench;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Holds the response for a chosen number of milliseconds and does nothing else.
 *
 * Sweeping the delay maps how the platform overhead varies with server time,
 * on the same path as every other dynamic route, so the measurement needs no
 * static baseline to compare against. The wait releases the CPU, which also
 * separates elapsed time from computation.
 */
class SleepController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $milliseconds = min(
            max($request->integer('ms'), 0),
            (int) config('bench.sleep.max_ms'),
        );

        usleep($milliseconds * 1000);

        return response()->json(['slept_ms' => $milliseconds]);
    }
}
