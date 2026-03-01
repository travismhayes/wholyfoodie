<?php

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds categories from config', function () {
    $this->artisan('seed:categories')
        ->expectsOutput('Seeded 7 categories.')
        ->assertExitCode(0);

    expect(Category::count())->toBe(7);
    expect(Category::where('slug', 'meat')->first()->name)->toBe('Meat & Poultry');
});

it('does not duplicate categories on re-run', function () {
    $this->artisan('seed:categories');
    $this->artisan('seed:categories');

    expect(Category::count())->toBe(7);
});
