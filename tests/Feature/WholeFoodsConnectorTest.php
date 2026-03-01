<?php

use App\Http\Integrations\WholeFoods\Requests\GetProductsRequest;
use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    Saloon\Config::preventStrayRequests();

    $rateLimitDir = storage_path('rate-limits');

    if (is_dir($rateLimitDir)) {
        foreach (glob("{$rateLimitDir}/*") as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

it('can fetch products from whole foods api', function () {
    $mockClient = new MockClient([
        GetProductsRequest::class => MockResponse::make([
            'products' => [
                ['id' => 1, 'name' => 'Organic Bananas'],
                ['id' => 2, 'name' => 'Almond Milk'],
            ],
        ], 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new GetProductsRequest('produce'));

    expect($response->status())->toBe(200);
    expect($response->json('products'))->toHaveCount(2);

    $mockClient->assertSent(GetProductsRequest::class);
});

it('handles api errors gracefully', function () {
    $mockClient = new MockClient([
        GetProductsRequest::class => MockResponse::make(['error' => 'Rate limited'], 429),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->useRateLimitPlugin(false);
    $connector->tries = 1;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new GetProductsRequest('produce'));

    expect($response->status())->toBe(429);
    expect($response->failed())->toBeTrue();
});

it('uses user agent from config', function () {
    config()->set('scraping.user_agent', 'TestAgent/1.0');

    $mockClient = new MockClient([
        GetProductsRequest::class => MockResponse::make(['products' => []], 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);

    $connector->send(new GetProductsRequest('produce'));

    $mockClient->assertSent(function ($request, $response) {
        return $response->getPendingRequest()->headers()->get('User-Agent') === 'TestAgent/1.0';
    });
});
