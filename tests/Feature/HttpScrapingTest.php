<?php

use Illuminate\Support\Facades\Http;

it('can fetch a page using http facade', function () {
    Http::fake([
        'example.com/*' => Http::response('<html><body>Hello</body></html>', 200),
    ]);

    $response = Http::get('https://example.com/page');

    expect($response->successful())->toBeTrue();
    expect($response->body())->toContain('Hello');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/page';
    });
});

it('handles failed requests', function () {
    Http::fake([
        '*' => Http::response('Server Error', 500),
    ]);

    $response = Http::get('https://example.com/page');

    expect($response->failed())->toBeTrue();
    expect($response->status())->toBe(500);
});
