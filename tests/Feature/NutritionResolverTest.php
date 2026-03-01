<?php

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
use App\Http\Integrations\Usda\UsdaConnector;
use App\Models\Category;
use App\Models\Product;
use App\Support\NutritionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Saloon\Config::preventStrayRequests();
});

it('resolves nutrition from open food facts', function () {
    $category = Category::create(['name' => 'Dairy', 'slug' => 'dairy']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Whole Milk',
        'brand' => '365',
        'price' => 4.99,
    ]);

    $mockClient = new MockClient([
        SearchProductsRequest::class => MockResponse::make([
            'products' => [
                [
                    'product_name' => 'Organic Whole Milk',
                    'nutriments' => [
                        'energy-kcal_serving' => 150,
                        'proteins_serving' => 8,
                        'fat_serving' => 8,
                        'carbohydrates_serving' => 12,
                        'fiber_serving' => 0,
                        'sugars_serving' => 12,
                        'sodium_serving' => 0.125,
                    ],
                    'serving_size' => '1 cup (240ml)',
                ],
            ],
        ], 200),
    ]);

    $offConnector = new OpenFoodFactsConnector;
    $offConnector->withMockClient($mockClient);

    $usdaConnector = new UsdaConnector;

    $resolver = new NutritionResolver($offConnector, $usdaConnector);
    $result = $resolver->resolve($product);

    expect($result)->not->toBeNull();
    expect($result['source'])->toBe('open_food_facts');
    expect($result['calories'])->toBe(150.0);
    expect($result['protein_g'])->toBe(8.0);
    expect($result['sodium_mg'])->toBe(125.0);
    expect($result['serving_size'])->toBe('1 cup (240ml)');
});

it('falls back to usda when open food facts has no results', function () {
    $category = Category::create(['name' => 'Meat', 'slug' => 'meat']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Chicken Breast',
        'price' => 6.99,
    ]);

    config()->set('scraping.usda.api_key', 'test-key');

    $offMock = new MockClient([
        SearchProductsRequest::class => MockResponse::make(['products' => []], 200),
    ]);

    $usdaMock = new MockClient([
        SearchFoodsRequest::class => MockResponse::make([
            'foods' => [
                [
                    'description' => 'Chicken, broilers or fryers, breast, skinless, boneless, meat only, raw',
                    'foodNutrients' => [
                        ['nutrientName' => 'Energy', 'value' => 120, 'unitName' => 'KCAL'],
                        ['nutrientName' => 'Protein', 'value' => 22.5, 'unitName' => 'G'],
                        ['nutrientName' => 'Total lipid (fat)', 'value' => 2.6, 'unitName' => 'G'],
                        ['nutrientName' => 'Carbohydrate, by difference', 'value' => 0, 'unitName' => 'G'],
                        ['nutrientName' => 'Fiber, total dietary', 'value' => 0, 'unitName' => 'G'],
                        ['nutrientName' => 'Sugars, total including NLEA', 'value' => 0, 'unitName' => 'G'],
                        ['nutrientName' => 'Sodium, Na', 'value' => 74, 'unitName' => 'MG'],
                    ],
                    'servingSize' => 100,
                    'servingSizeUnit' => 'g',
                ],
            ],
        ], 200),
    ]);

    $offConnector = new OpenFoodFactsConnector;
    $offConnector->withMockClient($offMock);

    $usdaConnector = new UsdaConnector;
    $usdaConnector->withMockClient($usdaMock);

    $resolver = new NutritionResolver($offConnector, $usdaConnector);
    $result = $resolver->resolve($product);

    expect($result)->not->toBeNull();
    expect($result['source'])->toBe('usda');
    expect($result['protein_g'])->toBe(22.5);
    expect($result['sodium_mg'])->toBe(74.0);
});

it('returns null when no source has data', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Mystery Fruit',
        'price' => 2.99,
    ]);

    config()->set('scraping.usda.api_key', 'test-key');

    $offMock = new MockClient([
        SearchProductsRequest::class => MockResponse::make(['products' => []], 200),
    ]);

    $usdaMock = new MockClient([
        SearchFoodsRequest::class => MockResponse::make(['foods' => []], 200),
    ]);

    $offConnector = new OpenFoodFactsConnector;
    $offConnector->withMockClient($offMock);

    $usdaConnector = new UsdaConnector;
    $usdaConnector->withMockClient($usdaMock);

    $resolver = new NutritionResolver($offConnector, $usdaConnector);

    expect($resolver->resolve($product))->toBeNull();
});
