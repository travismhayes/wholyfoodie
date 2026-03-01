<?php

use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
use App\Http\Integrations\Usda\UsdaConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    Saloon\Config::preventStrayRequests();
});

it('can search foods by name', function () {
    config()->set('scraping.usda.api_key', 'test-key');

    $mockClient = new MockClient([
        SearchFoodsRequest::class => MockResponse::make([
            'foods' => [
                [
                    'description' => 'Chicken breast, raw',
                    'foodNutrients' => [
                        ['nutrientName' => 'Energy', 'value' => 120, 'unitName' => 'KCAL'],
                        ['nutrientName' => 'Protein', 'value' => 22.5, 'unitName' => 'G'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new UsdaConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new SearchFoodsRequest('chicken breast'));

    expect($response->status())->toBe(200);
    expect($response->json('foods.0.description'))->toBe('Chicken breast, raw');
});
