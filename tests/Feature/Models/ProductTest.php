<?php

use App\Models\Category;
use App\Models\Nutrition;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes to products needing nutrition', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);

    $withNutrition = Product::create([
        'category_id' => $category->id,
        'name' => 'Bananas',
        'price' => 0.29,
    ]);

    Nutrition::create([
        'product_id' => $withNutrition->id,
        'calories' => 105,
        'source' => 'manual',
    ]);

    Product::create([
        'category_id' => $category->id,
        'name' => 'Avocados',
        'price' => 1.99,
    ]);

    expect(Product::needsNutrition()->count())->toBe(1);
    expect(Product::needsNutrition()->first()->name)->toBe('Avocados');
});
