<?php

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\GetProductByBarcodeRequest;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    Saloon\Config::preventStrayRequests();
});

it('can search products by name', function () {
    $mockClient = new MockClient([
        SearchProductsRequest::class => MockResponse::make([
            'products' => [
                [
                    'product_name' => 'Organic Whole Milk',
                    'brands' => '365',
                    'nutriments' => [
                        'energy-kcal_serving' => 150,
                        'proteins_serving' => 8,
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new OpenFoodFactsConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new SearchProductsRequest('365 Organic Whole Milk'));

    expect($response->status())->toBe(200);
    expect($response->json('products'))->toHaveCount(1);
    expect($response->json('products.0.product_name'))->toBe('Organic Whole Milk');
});

it('can look up a product by barcode', function () {
    $mockClient = new MockClient([
        GetProductByBarcodeRequest::class => MockResponse::make([
            'status' => 1,
            'product' => [
                'product_name' => 'Organic Bananas',
                'nutriments' => ['energy-kcal_serving' => 105],
            ],
        ], 200),
    ]);

    $connector = new OpenFoodFactsConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new GetProductByBarcodeRequest('0099482477073'));

    expect($response->status())->toBe(200);
    expect($response->json('product.product_name'))->toBe('Organic Bananas');
});
