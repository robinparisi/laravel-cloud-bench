<?php

namespace App\Http\Controllers\Bench;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The floor: routing and a response, no view, no database, no serialization.
 */
class NoopController extends Controller
{
    public function __invoke(): Response
    {
        return response('ok')->header('Content-Type', 'text/plain');
    }
}
