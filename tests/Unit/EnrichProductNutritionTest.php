<?php

use App\Actions\EnrichProductNutrition;
use App\Models\Category;
use App\Models\Nutrition;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('saves nutrition data to a product', function () {
    $category = Category::create(['name' => 'Dairy', 'slug' => 'dairy']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Whole Milk',
        'price' => 4.99,
    ]);

    (new EnrichProductNutrition)->execute($product, [
        'calories' => 150.0,
        'protein_g' => 8.0,
        'fat_g' => 8.0,
        'carbs_g' => 12.0,
        'fiber_g' => 0.0,
        'sugar_g' => 12.0,
        'sodium_mg' => 125.0,
        'serving_size' => '1 cup (240ml)',
        'source' => 'open_food_facts',
    ]);

    expect(Nutrition::count())->toBe(1);

    $nutrition = $product->fresh()->nutrition;
    expect($nutrition->source)->toBe('open_food_facts');
    expect((float) $nutrition->calories)->toBe(150.0);
});

it('updates existing nutrition when re-enriching', function () {
    $category = Category::create(['name' => 'Dairy', 'slug' => 'dairy']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Whole Milk',
        'price' => 4.99,
    ]);

    Nutrition::create([
        'product_id' => $product->id,
        'calories' => 100,
        'source' => 'manual',
    ]);

    (new EnrichProductNutrition)->execute($product, [
        'calories' => 150.0,
        'protein_g' => 8.0,
        'fat_g' => null,
        'carbs_g' => null,
        'fiber_g' => null,
        'sugar_g' => null,
        'sodium_mg' => null,
        'serving_size' => null,
        'source' => 'open_food_facts',
    ]);

    expect(Nutrition::count())->toBe(1);
    expect((float) $product->fresh()->nutrition->calories)->toBe(150.0);
    expect($product->fresh()->nutrition->source)->toBe('open_food_facts');
});
