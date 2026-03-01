<?php

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('knows when it needs scraping with no previous scrape', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);

    expect($category->needsScraping())->toBeTrue();
});

it('knows when it needs scraping after expiry', function () {
    $category = Category::create([
        'name' => 'Produce',
        'slug' => 'produce',
        'last_scraped_at' => now()->subDays(8),
    ]);

    expect($category->needsScraping())->toBeTrue();
});

it('knows when it is still fresh', function () {
    $category = Category::create([
        'name' => 'Produce',
        'slug' => 'produce',
        'last_scraped_at' => now()->subDay(),
    ]);

    expect($category->needsScraping())->toBeFalse();
});

it('scopes to categories that need scraping', function () {
    Category::create(['name' => 'Stale', 'slug' => 'stale', 'last_scraped_at' => now()->subDays(8)]);
    Category::create(['name' => 'Fresh', 'slug' => 'fresh', 'last_scraped_at' => now()->subDay()]);
    Category::create(['name' => 'Never', 'slug' => 'never']);

    expect(Category::forScraping()->count())->toBe(2);
});

it('scopes by slug', function () {
    Category::create(['name' => 'Produce', 'slug' => 'produce']);
    Category::create(['name' => 'Meat', 'slug' => 'meat']);

    expect(Category::bySlug('produce')->count())->toBe(1);
});
