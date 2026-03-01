<?php

use App\Actions\UpsertProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('creates a new product', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);

    $result = (new UpsertProduct)->execute($category, [
        'name' => 'Organic Bananas',
        'brand' => '365',
        'price' => '0.29',
        'unit' => '/ ea',
        'url' => '/products/bananas',
        'image_url' => 'https://cdn.example.com/bananas.jpg',
    ]);

    expect($result)->toBe('created');
    expect(Product::count())->toBe(1);
    expect(Product::first()->name)->toBe('Organic Bananas');
    expect(Product::first()->price)->toBe('0.29');
});

it('updates an existing product price', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);
    Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Bananas',
        'brand' => '365',
        'price' => 0.25,
    ]);

    $result = (new UpsertProduct)->execute($category, [
        'name' => 'Organic Bananas',
        'brand' => '365',
        'price' => '0.29',
        'unit' => '/ ea',
        'url' => null,
        'image_url' => null,
    ]);

    expect($result)->toBe('updated');
    expect(Product::count())->toBe(1);
    expect(Product::first()->price)->toBe('0.29');
});

it('treats different brands as different products', function () {
    $category = Category::create(['name' => 'Dairy', 'slug' => 'dairy']);

    $action = new UpsertProduct;

    $action->execute($category, [
        'name' => 'Whole Milk',
        'brand' => '365',
        'price' => '3.99',
        'unit' => null,
        'url' => null,
        'image_url' => null,
    ]);

    $action->execute($category, [
        'name' => 'Whole Milk',
        'brand' => 'Organic Valley',
        'price' => '5.99',
        'unit' => null,
        'url' => null,
        'image_url' => null,
    ]);

    expect(Product::count())->toBe(2);
});
