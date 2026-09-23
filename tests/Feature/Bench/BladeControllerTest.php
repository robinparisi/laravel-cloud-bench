<?php

test('renders the requested number of rows', function () {
    $response = $this->get(route('bench.blade', ['rows' => 7]));

    $response->assertOk();
    expect(substr_count($response->getContent(), '<article>'))->toBe(7);
});

test('renders the same markup whichever mode is used', function () {
    $component = $this->get(route('bench.blade', ['rows' => 3, 'mode' => 'component']))->getContent();
    $plain = $this->get(route('bench.blade', ['rows' => 3, 'mode' => 'plain']))->getContent();

    // Only the rendering path differs, so a mode comparison prices components alone.
    expect(preg_replace('/\s+/', '', $plain))->toBe(preg_replace('/\s+/', '', $component));
});

test('returns 422 when the render mode is unknown', function () {
    $this->get(route('bench.blade', ['mode' => 'twig']))->assertUnprocessable()->assertInvalid('mode');
});

test('clamps the row count at the configured maximum', function () {
    config(['bench.blade.max_rows' => 4]);

    $response = $this->get(route('bench.blade', ['rows' => 900]));

    expect(substr_count($response->getContent(), '<article>'))->toBe(4);
});
