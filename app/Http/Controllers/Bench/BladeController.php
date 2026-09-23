<?php

namespace App\Http\Controllers\Bench;

use App\Bench\RenderMode;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Enum;

/**
 * Renders a list of rows, the shape of work a real page actually does.
 *
 * The two modes differ only by how a row is emitted: inline markup, or one
 * Blade component per row. Comparing them prices a component render, which is
 * what a page paying for hundreds of them needs to know.
 */
class BladeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'mode' => ['sometimes', new Enum(RenderMode::class)],
        ]);

        $rows = min(
            max($request->integer('rows', (int) config('bench.blade.rows')), 1),
            (int) config('bench.blade.max_rows'),
        );

        return view('bench.list', [
            'mode' => RenderMode::tryFrom($validated['mode'] ?? '') ?? RenderMode::Component,
            'rows' => $this->rows($rows),
        ]);
    }

    /**
     * @return Collection<int, array{id: int, title: string, excerpt: string}>
     */
    private function rows(int $count): Collection
    {
        // Fixed content: varying row width would add its own noise to the measurement.
        $excerpt = str_repeat('lorem ipsum dolor sit amet ', 6);

        return Collection::range(1, $count)->map(fn (int $number): array => [
            'id' => $number,
            'title' => 'Bench row '.$number,
            'excerpt' => $excerpt,
        ]);
    }
}
