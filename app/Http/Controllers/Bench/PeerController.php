<?php

namespace App\Http\Controllers\Bench;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports who connected to the origin, seen from the origin.
 *
 * The client only ever sees the edge that answered it, which is the same for
 * every URL. Comparing this between a fast and a slow URL shows whether the
 * edge reaches the container over a different path.
 *
 * Deliberately a fixed list of fields rather than a header dump: the route is
 * public.
 */
class PeerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'remote_addr' => $request->server('REMOTE_ADDR'),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'cf_ray' => $request->header('CF-Ray'),
            'cf_connecting_ip' => $request->header('CF-Connecting-IP'),
            'cf_worker' => $request->header('CF-Worker'),
        ]);
    }
}
