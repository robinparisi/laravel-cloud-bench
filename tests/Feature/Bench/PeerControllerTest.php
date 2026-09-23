<?php

test('reports the connection as the origin sees it', function () {
    $response = $this->withHeaders(['CF-Ray' => 'abc123-CDG'])->get(route('bench.peer'));

    $response->assertOk()
        ->assertJsonPath('cf_ray', 'abc123-CDG')
        ->assertJsonStructure(['remote_addr', 'forwarded_for', 'cf_ray', 'cf_connecting_ip', 'cf_worker']);
});
