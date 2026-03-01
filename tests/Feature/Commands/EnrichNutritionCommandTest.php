<?php

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Models\Category;
use App\Models\Nutrition;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Saloon\Config::preventStrayRequests();
});

it('enriches products missing nutrition', function () {
    $category = Category::create(['name' => 'Dairy', 'slug' => 'dairy']);
    Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Milk',
        'brand' => '365',
        'price' => 4.99,
    ]);

    $mockClient = new MockClient([
        SearchProductsRequest::class => MockResponse::make([
            'products' => [[
                'product_name' => 'Organic Milk',
                'nutriments' => [
                    'energy-kcal_serving' => 150,
                    'proteins_serving' => 8,
                    'fat_serving' => 8,
                    'carbohydrates_serving' => 12,
                    'fiber_serving' => 0,
                    'sugars_serving' => 12,
                    'sodium_serving' => 0.125,
                ],
            ]],
        ], 200),
    ]);

    $offConnector = new OpenFoodFactsConnector;
    $offConnector->withMockClient($mockClient);
    $this->app->instance(OpenFoodFactsConnector::class, $offConnector);

    $this->artisan('enrich:nutrition')
        ->assertExitCode(0);

    expect(Nutrition::count())->toBe(1);
    expect(Nutrition::first()->source)->toBe('open_food_facts');
});

it('skips products that already have nutrition', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Bananas',
        'price' => 0.29,
    ]);

    Nutrition::create([
        'product_id' => $product->id,
        'calories' => 105,
        'source' => 'manual',
    ]);

    $this->artisan('enrich:nutrition')
        ->expectsOutput('All products already have nutrition data.')
        ->assertExitCode(0);

    expect(Nutrition::count())->toBe(1);
});
