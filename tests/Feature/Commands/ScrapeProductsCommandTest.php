<?php

use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use App\Models\Category;
use App\Models\Product;
use App\Models\ScrapeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Saloon\Config::preventStrayRequests();

    config()->set('scraping.delay.initial_seconds', 0);
    config()->set('scraping.delay.jitter_max_seconds', 0);
});

it('scrapes products and stores them', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);

    $html = <<<'HTML'
    <html><body>
        <div data-testid="product-tile">
            <h2>Organic Bananas</h2>
            <span class="w-pie--product-tile__brand">365</span>
            <span data-testid="product-tile-price">$0.29</span>
        </div>
        <div data-testid="product-tile">
            <h2>Organic Avocados</h2>
            <span class="w-pie--product-tile__brand">Whole Foods</span>
            <span data-testid="product-tile-price">$1.99</span>
        </div>
    </body></html>
    HTML;

    $mockClient = new MockClient([
        '*' => MockResponse::make($html, 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);
    $this->app->instance(WholeFoodsConnector::class, $connector);

    $this->artisan('scrape:products', ['--category' => 'produce', '--limit' => 1])
        ->assertExitCode(0);

    expect(Product::count())->toBe(2);
    expect(Product::where('name', 'Organic Bananas')->first()->price)->toBe('0.29');
    expect(ScrapeLog::count())->toBe(1);
    expect(ScrapeLog::first()->products_created)->toBe(2);
});

it('updates existing product prices on re-scrape', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);
    Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Bananas',
        'brand' => '365',
        'price' => 0.25,
    ]);

    $html = <<<'HTML'
    <html><body>
        <div data-testid="product-tile">
            <h2>Organic Bananas</h2>
            <span class="w-pie--product-tile__brand">365</span>
            <span data-testid="product-tile-price">$0.29</span>
        </div>
    </body></html>
    HTML;

    $mockClient = new MockClient([
        '*' => MockResponse::make($html, 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);
    $this->app->instance(WholeFoodsConnector::class, $connector);

    $this->artisan('scrape:products', ['--category' => 'produce', '--force' => true, '--limit' => 1])
        ->assertExitCode(0);

    expect(Product::count())->toBe(1);
    expect(Product::first()->price)->toBe('0.29');
    expect(ScrapeLog::first()->products_updated)->toBe(1);
});

it('skips fresh categories without --force', function () {
    Category::create([
        'name' => 'Produce',
        'slug' => 'produce',
        'last_scraped_at' => now(),
    ]);

    $this->artisan('scrape:products', ['--category' => 'produce'])
        ->expectsOutput('Skipping Produce — still fresh.')
        ->assertExitCode(0);

    expect(Product::count())->toBe(0);
});
