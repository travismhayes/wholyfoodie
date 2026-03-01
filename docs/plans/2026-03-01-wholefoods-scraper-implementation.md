# Whole Foods Scraper Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a CLI scraper that collects Whole Foods product data (name, brand, price) and enriches it with nutrition/macros from Open Food Facts and USDA, all stored in a local SQLite database.

**Architecture:** Saloon v3 connectors for all HTTP (Whole Foods site, Open Food Facts API, USDA API). Spatie Crawler + Symfony DomCrawler as fallback for HTML parsing. SQLite for storage. Config-driven rate limiting and retry behavior.

**Tech Stack:** Laravel Zero 12, Saloon v3, Spatie Crawler, Symfony DomCrawler, Pest 4, SQLite

**Design doc:** `docs/plans/2026-03-01-wholefoods-scraper-design.md`

---

### Task 1: Install Saloon Rate Limit Plugin

**Files:**
- Modify: `composer.json`

**Step 1: Install the package**

Run: `/Users/travishayes/Library/Application\ Support/Herd/bin/php84 /usr/local/bin/composer require saloonphp/rate-limit-plugin`

Expected: Package installs successfully.

**Step 2: Commit**

```bash
git add composer.json composer.lock
git commit -m "Add saloon rate-limit-plugin dependency"
```

---

### Task 2: Create scraping config file

**Files:**
- Create: `config/scraping.php`

**Step 1: Create the config file**

```php
<?php

return [
    'delay' => [
        'initial_seconds' => env('SCRAPE_INITIAL_DELAY', 5),
        'update_seconds' => env('SCRAPE_UPDATE_DELAY', 2),
        'jitter_max_seconds' => env('SCRAPE_JITTER_MAX', 3),
    ],

    'retry' => [
        'max_attempts' => env('SCRAPE_MAX_RETRIES', 3),
        'backoff_initial_seconds' => env('SCRAPE_BACKOFF_INITIAL', 30),
        'backoff_max_seconds' => env('SCRAPE_BACKOFF_MAX', 300),
    ],

    'rate_limit' => [
        'requests_per_minute' => env('SCRAPE_REQUESTS_PER_MINUTE', 12),
    ],

    'freshness' => [
        'category_max_age_days' => env('SCRAPE_FRESHNESS_DAYS', 7),
    ],

    'user_agent' => env('SCRAPE_USER_AGENT', 'WholeFoodsData/1.0 (personal meal planning tool)'),

    'categories' => [
        ['name' => 'Meat & Poultry', 'slug' => 'meat'],
        ['name' => 'Seafood', 'slug' => 'seafood'],
        ['name' => 'Produce', 'slug' => 'produce'],
        ['name' => 'Dairy & Eggs', 'slug' => 'dairy-eggs'],
        ['name' => 'Bread & Bakery', 'slug' => 'bread-rolls-bakery'],
        ['name' => 'Frozen Foods', 'slug' => 'frozen-foods'],
        ['name' => 'Pantry Staples', 'slug' => 'canned-goods-soups-broths'],
    ],

    'usda' => [
        'api_key' => env('USDA_API_KEY'),
        'requests_per_hour' => 3600,
    ],
];
```

**Step 2: Commit**

```bash
git add config/scraping.php
git commit -m "Add scraping config with rate limits, delays, and categories"
```

---

### Task 3: Create database migrations

**Files:**
- Create: `database/migrations/2026_03_01_000001_create_categories_table.php`
- Create: `database/migrations/2026_03_01_000002_create_products_table.php`
- Create: `database/migrations/2026_03_01_000003_create_nutrition_table.php`
- Create: `database/migrations/2026_03_01_000004_create_scrape_logs_table.php`

Delete the existing `database/migrations/2026_02_02_151440_create_flight_tables.php` — it's a placeholder from scaffolding.

**Step 1: Write the categories migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamp('last_scraped_at')->nullable();
            $table->integer('last_page_scraped')->default(0);
            $table->timestamps();
        });
    }
};
```

**Step 2: Write the products migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('asin')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->decimal('price', 8, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('image_url')->nullable();
            $table->string('whole_foods_url')->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'name', 'brand']);
        });
    }
};
```

**Step 3: Write the nutrition migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('calories', 8, 2)->nullable();
            $table->decimal('protein_g', 8, 2)->nullable();
            $table->decimal('fat_g', 8, 2)->nullable();
            $table->decimal('carbs_g', 8, 2)->nullable();
            $table->decimal('fiber_g', 8, 2)->nullable();
            $table->decimal('sugar_g', 8, 2)->nullable();
            $table->decimal('sodium_mg', 8, 2)->nullable();
            $table->string('serving_size')->nullable();
            $table->string('source');
            $table->timestamps();
        });
    }
};
```

**Step 4: Write the scrape_logs migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('command');
            $table->integer('products_found')->default(0);
            $table->integer('products_created')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('errors')->default(0);
            $table->json('error_details')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
};
```

**Step 5: Delete the flight_tables placeholder migration**

```bash
rm database/migrations/2026_02_02_151440_create_flight_tables.php
```

**Step 6: Run migrations to verify they work**

Run: `/Users/travishayes/Library/Application\ Support/Herd/bin/php84 wholefoods-data migrate`

Expected: All 4 tables created successfully.

**Step 7: Commit**

```bash
git add database/migrations/
git commit -m "Add categories, products, nutrition, and scrape_logs migrations"
```

---

### Task 4: Create Eloquent models

**Files:**
- Create: `app/Models/Category.php`
- Create: `app/Models/Product.php`
- Create: `app/Models/Nutrition.php`
- Create: `app/Models/ScrapeLog.php`

**Step 1: Write Category model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_scraped_at' => 'datetime',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function needsScraping(): bool
    {
        if (! $this->last_scraped_at) {
            return true;
        }

        $maxAgeDays = config('scraping.freshness.category_max_age_days', 7);

        return $this->last_scraped_at->diffInDays(now()) >= $maxAgeDays;
    }
}
```

**Step 2: Write Product model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'last_scraped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasOne<Nutrition, $this> */
    public function nutrition(): HasOne
    {
        return $this->hasOne(Nutrition::class);
    }

    public function hasNutrition(): bool
    {
        return $this->nutrition()->exists();
    }
}
```

**Step 3: Write Nutrition model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nutrition extends Model
{
    protected $table = 'nutrition';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'calories' => 'decimal:2',
            'protein_g' => 'decimal:2',
            'fat_g' => 'decimal:2',
            'carbs_g' => 'decimal:2',
            'fiber_g' => 'decimal:2',
            'sugar_g' => 'decimal:2',
            'sodium_mg' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

**Step 4: Write ScrapeLog model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'error_details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

**Step 5: Commit**

```bash
git add app/Models/
git commit -m "Add Category, Product, Nutrition, and ScrapeLog models"
```

---

### Task 5: Seed categories from config

**Files:**
- Create: `app/Commands/SeedCategoriesCommand.php`
- Test: `tests/Feature/SeedCategoriesCommandTest.php`

**Step 1: Write the failing test**

```php
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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter SeedCategoriesCommandTest`

Expected: FAIL — command not found.

**Step 3: Write the command**

```php
<?php

namespace App\Commands;

use App\Models\Category;
use LaravelZero\Framework\Commands\Command;

class SeedCategoriesCommand extends Command
{
    protected $signature = 'seed:categories';

    protected $description = 'Seed product categories from config';

    public function handle(): void
    {
        $categories = config('scraping.categories', []);
        $count = 0;

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name']]
            );
            $count++;
        }

        $this->info("Seeded {$count} categories.");
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter SeedCategoriesCommandTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Commands/SeedCategoriesCommand.php tests/Feature/SeedCategoriesCommandTest.php
git commit -m "Add seed:categories command with tests"
```

---

### Task 6: Update WholeFoodsConnector with rate limiting and retry

**Files:**
- Modify: `app/Http/Integrations/WholeFoods/WholeFoodsConnector.php`
- Modify: `tests/Feature/WholeFoodsConnectorTest.php`

**Step 1: Write the failing test for rate limit config**

Add to `tests/Feature/WholeFoodsConnectorTest.php`:

```php
it('uses user agent from config', function () {
    config()->set('scraping.user_agent', 'TestAgent/1.0');

    $mockClient = new MockClient([
        GetProductsRequest::class => MockResponse::make(['products' => []], 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);

    $connector->send(new GetProductsRequest);

    $mockClient->assertSent(function ($request) {
        return $request->headers()->get('User-Agent') === 'TestAgent/1.0';
    });
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter WholeFoodsConnectorTest`

Expected: FAIL — User-Agent is still hardcoded.

**Step 3: Update the connector**

```php
<?php

namespace App\Http\Integrations\WholeFoods;

use Saloon\Http\Connector;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\FileStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;

class WholeFoodsConnector extends Connector
{
    use HasRateLimits;

    public ?int $tries = 3;

    public ?int $retryInterval = 10000;

    public ?bool $useExponentialBackoff = true;

    public function resolveBaseUrl(): string
    {
        return 'https://www.wholefoodsmarket.com';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/json',
            'User-Agent' => config('scraping.user_agent', 'WholeFoodsData/1.0'),
        ];
    }

    /** @return array<int, Limit> */
    protected function resolveLimits(): array
    {
        return [
            Limit::allow(
                (int) config('scraping.rate_limit.requests_per_minute', 12)
            )->everyMinute(),
        ];
    }

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new FileStore(storage_path('rate-limits'));
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter WholeFoodsConnectorTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Integrations/WholeFoods/WholeFoodsConnector.php tests/Feature/WholeFoodsConnectorTest.php
git commit -m "Add rate limiting and retry logic to WholeFoodsConnector"
```

---

### Task 7: Create OpenFoodFactsConnector

**Files:**
- Create: `app/Http/Integrations/OpenFoodFacts/OpenFoodFactsConnector.php`
- Create: `app/Http/Integrations/OpenFoodFacts/Requests/SearchProductsRequest.php`
- Create: `app/Http/Integrations/OpenFoodFacts/Requests/GetProductByBarcodeRequest.php`
- Test: `tests/Feature/OpenFoodFactsConnectorTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Http\Integrations\OpenFoodFacts\Requests\GetProductByBarcodeRequest;
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
                        'fat_serving' => 8,
                        'carbohydrates_serving' => 12,
                        'fiber_serving' => 0,
                        'sugars_serving' => 12,
                        'sodium_serving' => 0.125,
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
                'nutriments' => [
                    'energy-kcal_serving' => 105,
                ],
            ],
        ], 200),
    ]);

    $connector = new OpenFoodFactsConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new GetProductByBarcodeRequest('0099482477073'));

    expect($response->status())->toBe(200);
    expect($response->json('product.product_name'))->toBe('Organic Bananas');
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter OpenFoodFactsConnectorTest`

Expected: FAIL — classes don't exist.

**Step 3: Write the connector and requests**

`app/Http/Integrations/OpenFoodFacts/OpenFoodFactsConnector.php`:

```php
<?php

namespace App\Http\Integrations\OpenFoodFacts;

use Saloon\Http\Connector;

class OpenFoodFactsConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://world.openfoodfacts.org';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => config('scraping.user_agent', 'WholeFoodsData/1.0'),
        ];
    }
}
```

`app/Http/Integrations/OpenFoodFacts/Requests/SearchProductsRequest.php`:

```php
<?php

namespace App\Http\Integrations\OpenFoodFacts\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class SearchProductsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $query, protected int $pageSize = 10) {}

    public function resolveEndpoint(): string
    {
        return '/cgi/search.pl';
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return [
            'search_terms' => $this->query,
            'action' => 'process',
            'json' => 1,
            'page_size' => $this->pageSize,
        ];
    }
}
```

`app/Http/Integrations/OpenFoodFacts/Requests/GetProductByBarcodeRequest.php`:

```php
<?php

namespace App\Http\Integrations\OpenFoodFacts\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetProductByBarcodeRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $barcode) {}

    public function resolveEndpoint(): string
    {
        return "/api/v2/product/{$this->barcode}";
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter OpenFoodFactsConnectorTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Integrations/OpenFoodFacts/ tests/Feature/OpenFoodFactsConnectorTest.php
git commit -m "Add OpenFoodFacts connector with search and barcode lookup"
```

---

### Task 8: Create UsdaConnector

**Files:**
- Create: `app/Http/Integrations/Usda/UsdaConnector.php`
- Create: `app/Http/Integrations/Usda/Requests/SearchFoodsRequest.php`
- Test: `tests/Feature/UsdaConnectorTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Http\Integrations\Usda\UsdaConnector;
use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
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
                        ['nutrientName' => 'Total lipid (fat)', 'value' => 2.6, 'unitName' => 'G'],
                        ['nutrientName' => 'Carbohydrate, by difference', 'value' => 0, 'unitName' => 'G'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new UsdaConnector;
    $connector->withMockClient($mockClient);

    $response = $connector->send(new SearchFoodsRequest('chicken breast'));

    expect($response->status())->toBe(200);
    expect($response->json('foods'))->toHaveCount(1);
    expect($response->json('foods.0.description'))->toBe('Chicken breast, raw');
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter UsdaConnectorTest`

Expected: FAIL — classes don't exist.

**Step 3: Write the connector and request**

`app/Http/Integrations/Usda/UsdaConnector.php`:

```php
<?php

namespace App\Http\Integrations\Usda;

use Saloon\Http\Connector;

class UsdaConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.nal.usda.gov/fdc/v1';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return [
            'api_key' => config('scraping.usda.api_key', ''),
        ];
    }
}
```

`app/Http/Integrations/Usda/Requests/SearchFoodsRequest.php`:

```php
<?php

namespace App\Http\Integrations\Usda\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class SearchFoodsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $query, protected int $pageSize = 5) {}

    public function resolveEndpoint(): string
    {
        return '/foods/search';
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return [
            'query' => $this->query,
            'pageSize' => $this->pageSize,
            'dataType' => 'SR Legacy,Foundation',
        ];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter UsdaConnectorTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Integrations/Usda/ tests/Feature/UsdaConnectorTest.php
git commit -m "Add USDA FoodData Central connector with food search"
```

---

### Task 9: Create scrape:discover command

This is the exploration command that hits the Whole Foods site to figure out what endpoints exist and how the data is structured. It does NOT persist data — it just prints findings to the console.

**Files:**
- Create: `app/Commands/ScrapeDiscoverCommand.php`
- Test: `tests/Feature/ScrapeDiscoverCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    Saloon\Config::preventStrayRequests();
});

it('discovers product page structure', function () {
    $html = <<<'HTML'
    <html>
    <body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Bananas</h2>
            <span class="text-left bds--heading-5">$0.29</span>
        </div>
    </body>
    </html>
    HTML;

    $mockClient = new MockClient([
        '*' => MockResponse::make($html, 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);
    $this->app->instance(WholeFoodsConnector::class, $connector);

    $this->artisan('scrape:discover', ['--category' => 'produce'])
        ->assertExitCode(0);
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter ScrapeDiscoverCommandTest`

Expected: FAIL — command not found.

**Step 3: Write the command**

```php
<?php

namespace App\Commands;

use App\Http\Integrations\WholeFoods\Requests\GetProductsRequest;
use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeDiscoverCommand extends Command
{
    protected $signature = 'scrape:discover
        {--category=produce : Category slug to explore}';

    protected $description = 'Explore the Whole Foods site to discover product page structure';

    public function handle(WholeFoodsConnector $connector): void
    {
        $category = $this->option('category');
        $this->info("Discovering product page structure for: {$category}");

        $request = new GetProductsRequest($category);
        $response = $connector->send($request);

        $this->line("Status: {$response->status()}");
        $this->line("Content-Type: {$response->header('Content-Type')}");

        $body = $response->body();
        $this->line("Body length: " . strlen($body) . " bytes");

        if (str_contains($response->header('Content-Type') ?? '', 'json')) {
            $this->info('Response is JSON — API endpoint found!');
            $this->line(json_encode(json_decode($body), JSON_PRETTY_PRINT));

            return;
        }

        $crawler = new Crawler($body);

        $productSelectors = [
            '[data-testid="product-tile"]',
            '.w-pie--product-tile',
            '.product-tile',
            '.product',
            '[class*="product"]',
        ];

        foreach ($productSelectors as $selector) {
            $count = $crawler->filter($selector)->count();
            $this->line("Selector '{$selector}': {$count} matches");
        }

        $this->newLine();
        $this->comment('Check output above to determine which selectors work.');
        $this->comment('Update GetProductsRequest and ProductCrawlObserver accordingly.');
    }
}
```

**Step 4: Update GetProductsRequest to accept a category slug**

```php
<?php

namespace App\Http\Integrations\WholeFoods\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetProductsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $category = '', protected int $page = 1) {}

    public function resolveEndpoint(): string
    {
        return "/products/{$this->category}";
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return [
            'page' => $this->page,
        ];
    }
}
```

**Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest --filter ScrapeDiscoverCommandTest`

Expected: PASS

**Step 6: Commit**

```bash
git add app/Commands/ScrapeDiscoverCommand.php app/Http/Integrations/WholeFoods/Requests/GetProductsRequest.php tests/Feature/ScrapeDiscoverCommandTest.php
git commit -m "Add scrape:discover command for site exploration"
```

---

### Task 10: Create scrape:products command

**Files:**
- Create: `app/Commands/ScrapeProductsCommand.php`
- Modify: `app/Crawlers/ProductCrawlObserver.php`
- Test: `tests/Feature/ScrapeProductsCommandTest.php`

**Step 1: Write the failing test**

```php
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
});

it('scrapes products for a category and stores them', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);

    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Bananas</h2>
            <span class="w-pie--product-tile__brand">365 by Whole Foods Market</span>
            <span data-testid="product-tile-price">$0.29</span>
            <span data-testid="product-tile-unit">/ ea</span>
        </div>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Avocados</h2>
            <span class="w-pie--product-tile__brand">Whole Foods Market</span>
            <span data-testid="product-tile-price">$1.99</span>
            <span data-testid="product-tile-unit">/ ea</span>
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
        'brand' => '365 by Whole Foods Market',
        'price' => 0.25,
    ]);

    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Bananas</h2>
            <span class="w-pie--product-tile__brand">365 by Whole Foods Market</span>
            <span data-testid="product-tile-price">$0.29</span>
            <span data-testid="product-tile-unit">/ ea</span>
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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter ScrapeProductsCommandTest`

Expected: FAIL — command not found.

**Step 3: Update ProductCrawlObserver to extract richer data**

Note: The CSS selectors below are placeholders based on research. They WILL need to be adjusted after running `scrape:discover` against the live site. That's expected — run discover first, then update these selectors to match what the site actually serves.

```php
<?php

namespace App\Crawlers;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Symfony\Component\DomCrawler\Crawler;

class ProductCrawlObserver extends CrawlObserver
{
    /** @var array<int, array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null}> */
    protected array $products = [];

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $html = (string) $response->getBody();
        $crawler = new Crawler($html);

        $crawler->filter('[data-testid="product-tile"]')->each(function (Crawler $node) {
            $this->products[] = [
                'name' => $this->extractText($node, 'h2'),
                'brand' => $this->extractText($node, '[class*="brand"]'),
                'price' => $this->extractPrice($node),
                'unit' => $this->extractText($node, '[data-testid="product-tile-unit"]'),
                'url' => $this->extractAttr($node, 'a', 'href'),
                'image_url' => $this->extractAttr($node, 'img', 'src'),
            ];
        });
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {}

    /** @return array<int, array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null}> */
    public function getProducts(): array
    {
        return $this->products;
    }

    protected function extractText(Crawler $node, string $selector): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? trim($filtered->text('')) : null;
    }

    protected function extractAttr(Crawler $node, string $selector, string $attr): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? $filtered->attr($attr) : null;
    }

    protected function extractPrice(Crawler $node): ?string
    {
        $text = $this->extractText($node, '[data-testid="product-tile-price"]');

        if (! $text) {
            return null;
        }

        preg_match('/[\d.]+/', $text, $matches);

        return $matches[0] ?? null;
    }
}
```

**Step 4: Write the command**

```php
<?php

namespace App\Commands;

use App\Http\Integrations\WholeFoods\Requests\GetProductsRequest;
use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use App\Models\Category;
use App\Models\Product;
use App\Models\ScrapeLog;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeProductsCommand extends Command
{
    protected $signature = 'scrape:products
        {--category= : Scrape a single category by slug}
        {--force : Ignore freshness interval}
        {--limit=10 : Max pages per category}';

    protected $description = 'Scrape products from Whole Foods';

    public function handle(WholeFoodsConnector $connector): void
    {
        $categories = $this->getCategories();

        if ($categories->isEmpty()) {
            $this->warn('No categories to scrape. Run seed:categories first.');

            return;
        }

        foreach ($categories as $category) {
            $this->scrapeCategory($connector, $category);
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Category> */
    protected function getCategories(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Category::query();

        if ($slug = $this->option('category')) {
            $query->where('slug', $slug);
        }

        return $query->get();
    }

    protected function scrapeCategory(WholeFoodsConnector $connector, Category $category): void
    {
        if (! $this->option('force') && ! $category->needsScraping()) {
            $this->line("Skipping {$category->name} — still fresh.");

            return;
        }

        $this->info("Scraping {$category->name}...");
        $startTime = now();
        $productsCreated = 0;
        $productsUpdated = 0;
        $productsFound = 0;
        $errors = 0;
        $errorDetails = [];
        $limit = (int) $this->option('limit');

        for ($page = 1; $page <= $limit; $page++) {
            $this->line("  Page {$page}...");

            $request = new GetProductsRequest($category->slug, $page);
            $response = $connector->send($request);

            if ($response->failed()) {
                $errors++;
                $errorDetails[] = [
                    'page' => $page,
                    'status' => $response->status(),
                ];
                $this->error("  HTTP {$response->status()} on page {$page}");

                break;
            }

            $products = $this->parseProducts($response->body());

            if (empty($products)) {
                $this->line('  No products found — end of pages.');

                break;
            }

            $productsFound += count($products);

            foreach ($products as $productData) {
                $result = $this->upsertProduct($category, $productData);
                $result === 'created' ? $productsCreated++ : $productsUpdated++;
            }

            $category->update(['last_page_scraped' => $page]);

            $this->applyDelay();
        }

        $category->update(['last_scraped_at' => now()]);

        ScrapeLog::create([
            'category_id' => $category->id,
            'command' => 'scrape:products',
            'products_found' => $productsFound,
            'products_created' => $productsCreated,
            'products_updated' => $productsUpdated,
            'errors' => $errors,
            'error_details' => $errorDetails ?: null,
            'duration_seconds' => $startTime->diffInSeconds(now()),
            'created_at' => now(),
        ]);

        $this->info("  Done: {$productsCreated} created, {$productsUpdated} updated, {$errors} errors.");
    }

    /** @return array<int, array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null}> */
    protected function parseProducts(string $html): array
    {
        $crawler = new Crawler($html);
        $products = [];

        $crawler->filter('[data-testid="product-tile"]')->each(function (Crawler $node) use (&$products) {
            $name = $this->extractText($node, 'h2');

            if (! $name) {
                return;
            }

            $products[] = [
                'name' => $name,
                'brand' => $this->extractText($node, '[class*="brand"]'),
                'price' => $this->extractPrice($node),
                'unit' => $this->extractText($node, '[data-testid="product-tile-unit"]'),
                'url' => $this->extractAttr($node, 'a', 'href'),
                'image_url' => $this->extractAttr($node, 'img', 'src'),
            ];
        });

        return $products;
    }

    /** @param array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null} $data */
    protected function upsertProduct(Category $category, array $data): string
    {
        $product = Product::where('category_id', $category->id)
            ->where('name', $data['name'])
            ->where('brand', $data['brand'])
            ->first();

        if ($product) {
            $product->update([
                'price' => $data['price'],
                'unit' => $data['unit'],
                'whole_foods_url' => $data['url'],
                'image_url' => $data['image_url'],
                'last_scraped_at' => now(),
            ]);

            return 'updated';
        }

        Product::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'brand' => $data['brand'],
            'price' => $data['price'],
            'unit' => $data['unit'],
            'whole_foods_url' => $data['url'],
            'image_url' => $data['image_url'],
            'last_scraped_at' => now(),
        ]);

        return 'created';
    }

    protected function applyDelay(): void
    {
        $baseDelay = (float) config('scraping.delay.initial_seconds', 5);
        $jitter = (float) config('scraping.delay.jitter_max_seconds', 3);
        $totalDelay = $baseDelay + (mt_rand(0, (int) ($jitter * 1000)) / 1000);

        usleep((int) ($totalDelay * 1_000_000));
    }

    protected function extractText(Crawler $node, string $selector): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? trim($filtered->text('')) : null;
    }

    protected function extractAttr(Crawler $node, string $selector, string $attr): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? $filtered->attr($attr) : null;
    }

    protected function extractPrice(Crawler $node): ?string
    {
        $text = $this->extractText($node, '[data-testid="product-tile-price"]');

        if (! $text) {
            return null;
        }

        preg_match('/[\d.]+/', $text, $matches);

        return $matches[0] ?? null;
    }
}
```

**Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest --filter ScrapeProductsCommandTest`

Expected: PASS

**Step 6: Commit**

```bash
git add app/Commands/ScrapeProductsCommand.php app/Crawlers/ProductCrawlObserver.php tests/Feature/ScrapeProductsCommandTest.php
git commit -m "Add scrape:products command with HTML parsing and upsert logic"
```

---

### Task 11: Create enrich:nutrition command

**Files:**
- Create: `app/Commands/EnrichNutritionCommand.php`
- Test: `tests/Feature/EnrichNutritionCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
use App\Http\Integrations\Usda\UsdaConnector;
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

it('enriches product nutrition from open food facts', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);
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
    $this->app->instance(OpenFoodFactsConnector::class, $offConnector);

    $this->artisan('enrich:nutrition')
        ->assertExitCode(0);

    expect(Nutrition::count())->toBe(1);

    $nutrition = $product->fresh()->nutrition;
    expect($nutrition->source)->toBe('open_food_facts');
    expect((float) $nutrition->calories)->toBe(150.0);
    expect((float) $nutrition->protein_g)->toBe(8.0);
});

it('falls back to usda when open food facts has no results', function () {
    $category = Category::create(['name' => 'Meat & Poultry', 'slug' => 'meat']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Chicken Breast',
        'brand' => null,
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
    $this->app->instance(OpenFoodFactsConnector::class, $offConnector);

    $usdaConnector = new UsdaConnector;
    $usdaConnector->withMockClient($usdaMock);
    $this->app->instance(UsdaConnector::class, $usdaConnector);

    $this->artisan('enrich:nutrition')
        ->assertExitCode(0);

    $nutrition = $product->fresh()->nutrition;
    expect($nutrition->source)->toBe('usda');
    expect((float) $nutrition->protein_g)->toBe(22.5);
});

it('skips products that already have nutrition', function () {
    $category = Category::create(['name' => 'Produce', 'slug' => 'produce']);
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Organic Bananas',
        'price' => 0.29,
    ]);

    Nutrition::create([
        'product_id' => $product->id,
        'calories' => 105,
        'protein_g' => 1.3,
        'fat_g' => 0.4,
        'carbs_g' => 27,
        'source' => 'manual',
    ]);

    $this->artisan('enrich:nutrition')
        ->assertExitCode(0);

    expect(Nutrition::count())->toBe(1);
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter EnrichNutritionCommandTest`

Expected: FAIL — command not found.

**Step 3: Write the command**

```php
<?php

namespace App\Commands;

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
use App\Http\Integrations\Usda\UsdaConnector;
use App\Models\Nutrition;
use App\Models\Product;
use LaravelZero\Framework\Commands\Command;

class EnrichNutritionCommand extends Command
{
    protected $signature = 'enrich:nutrition
        {--source= : Only use a specific source (open_food_facts, usda)}
        {--force : Re-enrich products that already have nutrition}
        {--limit=100 : Max products to enrich per run}';

    protected $description = 'Enrich products with nutrition data from Open Food Facts and USDA';

    public function handle(
        OpenFoodFactsConnector $offConnector,
        UsdaConnector $usdaConnector,
    ): void {
        $query = Product::query();

        if (! $this->option('force')) {
            $query->whereDoesntHave('nutrition');
        }

        $products = $query->limit((int) $this->option('limit'))->get();

        if ($products->isEmpty()) {
            $this->info('All products already have nutrition data.');

            return;
        }

        $this->info("Enriching {$products->count()} products...");
        $enriched = 0;
        $failed = 0;

        foreach ($products as $product) {
            $this->line("  {$product->name}...");

            $source = $this->option('source');

            if (! $source || $source === 'open_food_facts') {
                $nutrition = $this->tryOpenFoodFacts($offConnector, $product);

                if ($nutrition) {
                    $this->saveNutrition($product, $nutrition, 'open_food_facts');
                    $enriched++;

                    continue;
                }
            }

            if (! $source || $source === 'usda') {
                $nutrition = $this->tryUsda($usdaConnector, $product);

                if ($nutrition) {
                    $this->saveNutrition($product, $nutrition, 'usda');
                    $enriched++;

                    continue;
                }
            }

            $failed++;
            $this->warn("    No nutrition data found.");
        }

        $this->info("Done: {$enriched} enriched, {$failed} not found.");
    }

    /** @return array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null}|null */
    protected function tryOpenFoodFacts(OpenFoodFactsConnector $connector, Product $product): ?array
    {
        $searchTerm = trim("{$product->brand} {$product->name}");
        $response = $connector->send(new SearchProductsRequest($searchTerm, 3));

        if ($response->failed()) {
            return null;
        }

        $products = $response->json('products', []);

        if (empty($products)) {
            return null;
        }

        $match = $products[0];
        $nutriments = $match['nutriments'] ?? [];

        return [
            'calories' => $nutriments['energy-kcal_serving'] ?? $nutriments['energy-kcal_100g'] ?? null,
            'protein_g' => $nutriments['proteins_serving'] ?? $nutriments['proteins_100g'] ?? null,
            'fat_g' => $nutriments['fat_serving'] ?? $nutriments['fat_100g'] ?? null,
            'carbs_g' => $nutriments['carbohydrates_serving'] ?? $nutriments['carbohydrates_100g'] ?? null,
            'fiber_g' => $nutriments['fiber_serving'] ?? $nutriments['fiber_100g'] ?? null,
            'sugar_g' => $nutriments['sugars_serving'] ?? $nutriments['sugars_100g'] ?? null,
            'sodium_mg' => $this->sodiumToMg($nutriments['sodium_serving'] ?? $nutriments['sodium_100g'] ?? null),
            'serving_size' => $match['serving_size'] ?? null,
        ];
    }

    /** @return array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null}|null */
    protected function tryUsda(UsdaConnector $connector, Product $product): ?array
    {
        $response = $connector->send(new SearchFoodsRequest($product->name, 3));

        if ($response->failed()) {
            return null;
        }

        $foods = $response->json('foods', []);

        if (empty($foods)) {
            return null;
        }

        $match = $foods[0];
        $nutrients = collect($match['foodNutrients'] ?? []);

        $servingSize = isset($match['servingSize'])
            ? "{$match['servingSize']}{$match['servingSizeUnit'] ?? 'g'}"
            : null;

        return [
            'calories' => $this->findNutrient($nutrients, 'Energy'),
            'protein_g' => $this->findNutrient($nutrients, 'Protein'),
            'fat_g' => $this->findNutrient($nutrients, 'Total lipid (fat)'),
            'carbs_g' => $this->findNutrient($nutrients, 'Carbohydrate, by difference'),
            'fiber_g' => $this->findNutrient($nutrients, 'Fiber, total dietary'),
            'sugar_g' => $this->findNutrient($nutrients, 'Sugars, total including NLEA'),
            'sodium_mg' => $this->findNutrient($nutrients, 'Sodium, Na'),
            'serving_size' => $servingSize,
        ];
    }

    /** @param \Illuminate\Support\Collection<int, array{nutrientName: string, value: float, unitName: string}> $nutrients */
    protected function findNutrient(\Illuminate\Support\Collection $nutrients, string $name): ?float
    {
        $nutrient = $nutrients->firstWhere('nutrientName', $name);

        return $nutrient ? (float) $nutrient['value'] : null;
    }

    /** @param array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null} $data */
    protected function saveNutrition(Product $product, array $data, string $source): void
    {
        Nutrition::updateOrCreate(
            ['product_id' => $product->id],
            [
                ...$data,
                'source' => $source,
            ]
        );

        $this->info("    Saved from {$source}: {$data['calories']} cal");
    }

    protected function sodiumToMg(?float $sodiumGrams): ?float
    {
        if ($sodiumGrams === null) {
            return null;
        }

        return $sodiumGrams * 1000;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter EnrichNutritionCommandTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Commands/EnrichNutritionCommand.php tests/Feature/EnrichNutritionCommandTest.php
git commit -m "Add enrich:nutrition command with Open Food Facts and USDA fallback"
```

---

### Task 12: Create scrape:update convenience command

**Files:**
- Create: `app/Commands/ScrapeUpdateCommand.php`
- Test: `tests/Feature/ScrapeUpdateCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Saloon\Config::preventStrayRequests();

    $mockClient = MockClient::global([
        '*' => MockResponse::make('<html><body></body></html>', 200),
    ]);
});

it('runs scrape and enrich in sequence', function () {
    Category::create(['name' => 'Produce', 'slug' => 'produce']);

    $this->artisan('scrape:update')
        ->assertExitCode(0);
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter ScrapeUpdateCommandTest`

Expected: FAIL — command not found.

**Step 3: Write the command**

```php
<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ScrapeUpdateCommand extends Command
{
    protected $signature = 'scrape:update
        {--category= : Update a single category by slug}
        {--limit=10 : Max pages per category}';

    protected $description = 'Run incremental scrape and enrich nutrition for new products';

    public function handle(): void
    {
        $this->info('Starting incremental update...');

        $scrapeArgs = ['--limit' => $this->option('limit')];

        if ($category = $this->option('category')) {
            $scrapeArgs['--category'] = $category;
        }

        $this->call('scrape:products', $scrapeArgs);
        $this->call('enrich:nutrition');

        $this->info('Update complete.');
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter ScrapeUpdateCommandTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Commands/ScrapeUpdateCommand.php tests/Feature/ScrapeUpdateCommandTest.php
git commit -m "Add scrape:update convenience command"
```

---

### Task 13: Create .env file and run full test suite

**Files:**
- Create: `.env`

**Step 1: Create .env with required keys**

```
USDA_API_KEY=
```

**Step 2: Run full test suite**

Run: `./vendor/bin/pest`

Expected: All tests pass.

**Step 3: Run Pint**

Run: `./vendor/bin/pint --dirty`

Fix any formatting issues.

**Step 4: Commit everything**

```bash
git add .env
git commit -m "Add .env with USDA API key placeholder"
```

---

### Task 14: Run scrape:discover against the live site

This is a manual step — not automated. After all the code is in place:

1. Run: `php wholefoods-data seed:categories`
2. Run: `php wholefoods-data migrate`
3. Run: `php wholefoods-data scrape:discover --category=produce`

Review the output to see:
- Which CSS selectors actually match product tiles
- Whether the response is HTML or JSON
- What the actual page structure looks like

Then update `ScrapeProductsCommand`'s selectors to match reality. This may require iteration.
