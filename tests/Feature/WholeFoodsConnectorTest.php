<?php

use App\Http\Integrations\WholeFoods\Requests\GetProductsRequest;
use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    Saloon\Config::preventStrayRequests();
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

    $response = $connector->send(new GetProductsRequest);

    expect($response->status())->toBe(200);
    expect($response->json('products'))->toHaveCount(2);

    $mockClient->assertSent(GetProductsRequest::class);
});

it('handles api errors gracefully', function () {
    $mockClient = new MockClient([
        GetProductsRequest::class => MockResponse::make(['error' => 'Rate limited'], 429),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new GetProductsRequest);

    expect($response->status())->toBe(429);
    expect($response->failed())->toBeTrue();
});
