# Whole Foods Scraper Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a CLI scraper that collects Whole Foods product data (name, brand, price) and enriches it with nutrition/macros from Open Food Facts and USDA, all stored in a local SQLite database.

**Architecture:** Thin commands delegate to Action and Support classes. Saloon v3 connectors for all HTTP. Fat models own their scopes and domain logic. Support classes handle parsing and data mapping.

**Tech Stack:** Laravel Zero 12, Saloon v3, Spatie Crawler, Symfony DomCrawler, Pest 4, SQLite

**Design doc:** `docs/plans/2026-03-01-wholefoods-scraper-design.md`

**Class map:**
```
app/
├── Actions/
│   ├── UpsertProduct.php          # Create or update a product from parsed data
│   └── EnrichProductNutrition.php # Save nutrition data to a product
├── Commands/
│   ├── SeedCategoriesCommand.php
│   ├── ScrapeDiscoverCommand.php
│   ├── ScrapeProductsCommand.php  # Thin — delegates to parser + action
│   ├── EnrichNutritionCommand.php # Thin — delegates to resolver + action
│   └── ScrapeUpdateCommand.php    # Convenience wrapper
├── Http/Integrations/
│   ├── WholeFoods/
│   ├── OpenFoodFacts/
│   └── Usda/
├── Models/
│   ├── Category.php               # scopeForScraping(), needsScraping()
│   ├── Product.php                 # scopeNeedsNutrition()
│   ├── Nutrition.php
│   └── ScrapeLog.php
└── Support/
    ├── ProductPageParser.php       # HTML → product data arrays
    └── NutritionResolver.php       # Tries OFF → USDA, returns normalized data
```

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
- Delete: `database/migrations/2026_02_02_151440_create_flight_tables.php`

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

### Task 4: Create Eloquent models with scopes

**Files:**
- Create: `app/Models/Category.php`
- Create: `app/Models/Product.php`
- Create: `app/Models/Nutrition.php`
- Create: `app/Models/ScrapeLog.php`
- Test: `tests/Feature/Models/CategoryTest.php`
- Test: `tests/Feature/Models/ProductTest.php`

**Step 1: Write the failing tests**

`tests/Feature/Models/CategoryTest.php`:

```php
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
```

`tests/Feature/Models/ProductTest.php`:

```php
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
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest --filter CategoryTest --filter ProductTest`

Expected: FAIL — models don't exist.

**Step 3: Write Category model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /** @param Builder<self> $query */
    public function scopeForScraping(Builder $query): void
    {
        $maxAgeDays = config('scraping.freshness.category_max_age_days', 7);

        $query->where(function (Builder $q) use ($maxAgeDays) {
            $q->whereNull('last_scraped_at')
                ->orWhere('last_scraped_at', '<=', now()->subDays($maxAgeDays));
        });
    }

    /** @param Builder<self> $query */
    public function scopeBySlug(Builder $query, string $slug): void
    {
        $query->where('slug', $slug);
    }
}
```

**Step 4: Write Product model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /** @param Builder<self> $query */
    public function scopeNeedsNutrition(Builder $query): void
    {
        $query->whereDoesntHave('nutrition');
    }
}
```

**Step 5: Write Nutrition model**

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

**Step 6: Write ScrapeLog model**

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

**Step 7: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter CategoryTest --filter ProductTest`

Expected: PASS

**Step 8: Commit**

```bash
git add app/Models/ tests/Feature/Models/
git commit -m "Add Category, Product, Nutrition, ScrapeLog models with scopes"
```

---

### Task 5: Seed categories command

**Files:**
- Create: `app/Commands/SeedCategoriesCommand.php`
- Test: `tests/Feature/Commands/SeedCategoriesCommandTest.php`

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
git add app/Commands/SeedCategoriesCommand.php tests/Feature/Commands/SeedCategoriesCommandTest.php
git commit -m "Add seed:categories command with tests"
```

---

### Task 6: Update WholeFoodsConnector with rate limiting and retry

**Files:**
- Modify: `app/Http/Integrations/WholeFoods/WholeFoodsConnector.php`
- Modify: `app/Http/Integrations/WholeFoods/Requests/GetProductsRequest.php`
- Modify: `tests/Feature/WholeFoodsConnectorTest.php`

**Step 1: Write the failing test**

Add to `tests/Feature/WholeFoodsConnectorTest.php`:

```php
it('uses user agent from config', function () {
    config()->set('scraping.user_agent', 'TestAgent/1.0');

    $mockClient = new MockClient([
        GetProductsRequest::class => MockResponse::make(['products' => []], 200),
    ]);

    $connector = new WholeFoodsConnector;
    $connector->withMockClient($mockClient);

    $connector->send(new GetProductsRequest('produce'));

    $mockClient->assertSent(function ($request) {
        return $request->headers()->get('User-Agent') === 'TestAgent/1.0';
    });
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter WholeFoodsConnectorTest`

Expected: FAIL — User-Agent still hardcoded, GetProductsRequest doesn't accept args.

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

**Step 4: Update GetProductsRequest to accept category and page**

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

**Step 5: Update existing tests for new constructor signature**

The existing tests in `WholeFoodsConnectorTest.php` need `new GetProductsRequest` changed to `new GetProductsRequest('produce')` since the constructor now requires a category. Update both existing `it()` blocks.

**Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter WholeFoodsConnectorTest`

Expected: PASS

**Step 7: Commit**

```bash
git add app/Http/Integrations/WholeFoods/ tests/Feature/WholeFoodsConnectorTest.php
git commit -m "Add rate limiting and retry logic to WholeFoodsConnector"
```

---

### Task 7: Create OpenFoodFacts connector

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
                'nutriments' => ['energy-kcal_serving' => 105],
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

**Step 3: Write the connector**

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

**Step 4: Write SearchProductsRequest**

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

**Step 5: Write GetProductByBarcodeRequest**

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

**Step 6: Run test to verify it passes**

Run: `./vendor/bin/pest --filter OpenFoodFactsConnectorTest`

Expected: PASS

**Step 7: Commit**

```bash
git add app/Http/Integrations/OpenFoodFacts/ tests/Feature/OpenFoodFactsConnectorTest.php
git commit -m "Add OpenFoodFacts connector with search and barcode lookup"
```

---

### Task 8: Create USDA connector

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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter UsdaConnectorTest`

Expected: FAIL — classes don't exist.

**Step 3: Write the connector**

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

**Step 4: Write SearchFoodsRequest**

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

**Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest --filter UsdaConnectorTest`

Expected: PASS

**Step 6: Commit**

```bash
git add app/Http/Integrations/Usda/ tests/Feature/UsdaConnectorTest.php
git commit -m "Add USDA FoodData Central connector with food search"
```

---

### Task 9: Create ProductPageParser support class

This is the class that owns all HTML parsing logic. It takes raw HTML and returns clean, normalized product data arrays. When selectors change (and they will), you only update this one file.

**Files:**
- Create: `app/Support/ProductPageParser.php`
- Test: `tests/Unit/ProductPageParserTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Support\ProductPageParser;

it('parses product tiles from html', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Bananas</h2>
            <span class="w-pie--product-tile__brand">365 by Whole Foods Market</span>
            <span data-testid="product-tile-price">$0.29</span>
            <span data-testid="product-tile-unit">/ ea</span>
            <a href="/products/bananas-123">Link</a>
            <img src="https://cdn.example.com/bananas.jpg" />
        </div>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Avocados</h2>
            <span class="w-pie--product-tile__brand">Whole Foods Market</span>
            <span data-testid="product-tile-price">$1.99</span>
            <span data-testid="product-tile-unit">/ ea</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products)->toHaveCount(2);

    expect($products[0])->toMatchArray([
        'name' => 'Organic Bananas',
        'brand' => '365 by Whole Foods Market',
        'price' => '0.29',
        'unit' => '/ ea',
        'url' => '/products/bananas-123',
        'image_url' => 'https://cdn.example.com/bananas.jpg',
    ]);

    expect($products[1]['name'])->toBe('Organic Avocados');
    expect($products[1]['price'])->toBe('1.99');
});

it('returns empty array for html with no products', function () {
    $html = '<html><body><p>No products here</p></body></html>';

    $parser = new ProductPageParser;

    expect($parser->parse($html))->toBeEmpty();
});

it('skips tiles with no product name', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <span data-testid="product-tile-price">$0.29</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;

    expect($parser->parse($html))->toBeEmpty();
});

it('extracts price as numeric string without dollar sign', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2>Salmon Fillet</h2>
            <span data-testid="product-tile-price">$12.99 /lb</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products[0]['price'])->toBe('12.99');
});
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter ProductPageParserTest`

Expected: FAIL — class doesn't exist.

**Step 3: Write the parser**

```php
<?php

namespace App\Support;

use Symfony\Component\DomCrawler\Crawler;

class ProductPageParser
{
    protected string $tileSelector = '[data-testid="product-tile"]';

    protected string $nameSelector = 'h2';

    protected string $brandSelector = '[class*="brand"]';

    protected string $priceSelector = '[data-testid="product-tile-price"]';

    protected string $unitSelector = '[data-testid="product-tile-unit"]';

    /** @return array<int, array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null}> */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $products = [];

        $crawler->filter($this->tileSelector)->each(function (Crawler $node) use (&$products) {
            $name = $this->text($node, $this->nameSelector);

            if (! $name) {
                return;
            }

            $products[] = [
                'name' => $name,
                'brand' => $this->text($node, $this->brandSelector),
                'price' => $this->price($node),
                'unit' => $this->text($node, $this->unitSelector),
                'url' => $this->attr($node, 'a', 'href'),
                'image_url' => $this->attr($node, 'img', 'src'),
            ];
        });

        return $products;
    }

    protected function text(Crawler $node, string $selector): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? trim($filtered->text('')) : null;
    }

    protected function attr(Crawler $node, string $selector, string $attribute): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? $filtered->attr($attribute) : null;
    }

    protected function price(Crawler $node): ?string
    {
        $text = $this->text($node, $this->priceSelector);

        if (! $text) {
            return null;
        }

        preg_match('/[\d.]+/', $text, $matches);

        return $matches[0] ?? null;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter ProductPageParserTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Support/ProductPageParser.php tests/Unit/ProductPageParserTest.php
git commit -m "Add ProductPageParser for HTML product extraction"
```

---

### Task 10: Create UpsertProduct action

Simple action: takes a Category and a parsed product data array, creates or updates, returns what happened.

**Files:**
- Create: `app/Actions/UpsertProduct.php`
- Test: `tests/Unit/UpsertProductTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Actions\UpsertProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter UpsertProductTest`

Expected: FAIL — class doesn't exist.

**Step 3: Write the action**

```php
<?php

namespace App\Actions;

use App\Models\Category;
use App\Models\Product;

class UpsertProduct
{
    /** @param array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null} $data */
    public function execute(Category $category, array $data): string
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
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter UpsertProductTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Actions/UpsertProduct.php tests/Unit/UpsertProductTest.php
git commit -m "Add UpsertProduct action"
```

---

### Task 11: Create NutritionResolver support class

This is the brains behind nutrition enrichment. It tries Open Food Facts, then USDA, and returns normalized data. The command just calls this and saves the result.

**Files:**
- Create: `app/Support/NutritionResolver.php`
- Test: `tests/Feature/NutritionResolverTest.php`

**Step 1: Write the failing test**

```php
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
                    'description' => 'Chicken breast, raw',
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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter NutritionResolverTest`

Expected: FAIL — class doesn't exist.

**Step 3: Write the resolver**

```php
<?php

namespace App\Support;

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
use App\Http\Integrations\Usda\UsdaConnector;
use App\Models\Product;

class NutritionResolver
{
    public function __construct(
        protected OpenFoodFactsConnector $offConnector,
        protected UsdaConnector $usdaConnector,
    ) {}

    /** @return array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null, source: string}|null */
    public function resolve(Product $product): ?array
    {
        return $this->tryOpenFoodFacts($product)
            ?? $this->tryUsda($product);
    }

    /** @return array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null, source: string}|null */
    protected function tryOpenFoodFacts(Product $product): ?array
    {
        $searchTerm = trim("{$product->brand} {$product->name}");
        $response = $this->offConnector->send(new SearchProductsRequest($searchTerm, 3));

        if ($response->failed()) {
            return null;
        }

        $products = $response->json('products', []);

        if (empty($products)) {
            return null;
        }

        $nutriments = $products[0]['nutriments'] ?? [];

        return [
            'calories' => $this->floatOrNull($nutriments['energy-kcal_serving'] ?? $nutriments['energy-kcal_100g'] ?? null),
            'protein_g' => $this->floatOrNull($nutriments['proteins_serving'] ?? $nutriments['proteins_100g'] ?? null),
            'fat_g' => $this->floatOrNull($nutriments['fat_serving'] ?? $nutriments['fat_100g'] ?? null),
            'carbs_g' => $this->floatOrNull($nutriments['carbohydrates_serving'] ?? $nutriments['carbohydrates_100g'] ?? null),
            'fiber_g' => $this->floatOrNull($nutriments['fiber_serving'] ?? $nutriments['fiber_100g'] ?? null),
            'sugar_g' => $this->floatOrNull($nutriments['sugars_serving'] ?? $nutriments['sugars_100g'] ?? null),
            'sodium_mg' => $this->sodiumToMg($nutriments['sodium_serving'] ?? $nutriments['sodium_100g'] ?? null),
            'serving_size' => $products[0]['serving_size'] ?? null,
            'source' => 'open_food_facts',
        ];
    }

    /** @return array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null, source: string}|null */
    protected function tryUsda(Product $product): ?array
    {
        $response = $this->usdaConnector->send(new SearchFoodsRequest($product->name, 3));

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
            'calories' => $this->nutrient($nutrients, 'Energy'),
            'protein_g' => $this->nutrient($nutrients, 'Protein'),
            'fat_g' => $this->nutrient($nutrients, 'Total lipid (fat)'),
            'carbs_g' => $this->nutrient($nutrients, 'Carbohydrate, by difference'),
            'fiber_g' => $this->nutrient($nutrients, 'Fiber, total dietary'),
            'sugar_g' => $this->nutrient($nutrients, 'Sugars, total including NLEA'),
            'sodium_mg' => $this->nutrient($nutrients, 'Sodium, Na'),
            'serving_size' => $servingSize,
            'source' => 'usda',
        ];
    }

    /** @param \Illuminate\Support\Collection<int, array{nutrientName: string, value: float, unitName: string}> $nutrients */
    protected function nutrient(\Illuminate\Support\Collection $nutrients, string $name): ?float
    {
        $match = $nutrients->firstWhere('nutrientName', $name);

        return $match ? (float) $match['value'] : null;
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }

    protected function sodiumToMg(mixed $sodiumGrams): ?float
    {
        if ($sodiumGrams === null) {
            return null;
        }

        return (float) $sodiumGrams * 1000;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter NutritionResolverTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Support/NutritionResolver.php tests/Feature/NutritionResolverTest.php
git commit -m "Add NutritionResolver with Open Food Facts and USDA fallback"
```

---

### Task 12: Create EnrichProductNutrition action

Thin action that saves resolved nutrition data to a product.

**Files:**
- Create: `app/Actions/EnrichProductNutrition.php`
- Test: `tests/Unit/EnrichProductNutritionTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Actions\EnrichProductNutrition;
use App\Models\Category;
use App\Models\Nutrition;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter EnrichProductNutritionTest`

Expected: FAIL — class doesn't exist.

**Step 3: Write the action**

```php
<?php

namespace App\Actions;

use App\Models\Nutrition;
use App\Models\Product;

class EnrichProductNutrition
{
    /** @param array{calories: float|null, protein_g: float|null, fat_g: float|null, carbs_g: float|null, fiber_g: float|null, sugar_g: float|null, sodium_mg: float|null, serving_size: string|null, source: string} $data */
    public function execute(Product $product, array $data): void
    {
        Nutrition::updateOrCreate(
            ['product_id' => $product->id],
            $data,
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter EnrichProductNutritionTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Actions/EnrichProductNutrition.php tests/Unit/EnrichProductNutritionTest.php
git commit -m "Add EnrichProductNutrition action"
```

---

### Task 13: Create scrape:discover command

Exploration command — hits the Whole Foods site and prints what it finds. Does not persist data.

**Files:**
- Create: `app/Commands/ScrapeDiscoverCommand.php`
- Test: `tests/Feature/Commands/ScrapeDiscoverCommandTest.php`

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
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2>Organic Bananas</h2>
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
use App\Support\ProductPageParser;
use LaravelZero\Framework\Commands\Command;

class ScrapeDiscoverCommand extends Command
{
    protected $signature = 'scrape:discover
        {--category=produce : Category slug to explore}';

    protected $description = 'Explore the Whole Foods site to discover product page structure';

    public function handle(WholeFoodsConnector $connector, ProductPageParser $parser): void
    {
        $category = $this->option('category');
        $this->info("Discovering product page structure for: {$category}");

        $response = $connector->send(new GetProductsRequest($category));

        $this->line("Status: {$response->status()}");
        $this->line("Content-Type: {$response->header('Content-Type')}");
        $this->line("Body length: " . strlen($response->body()) . " bytes");

        if (str_contains($response->header('Content-Type') ?? '', 'json')) {
            $this->info('Response is JSON — API endpoint found!');
            $this->line(json_encode(json_decode($response->body()), JSON_PRETTY_PRINT));

            return;
        }

        $products = $parser->parse($response->body());
        $this->info("Found {$count = count($products)} products with current selectors.");

        foreach (array_slice($products, 0, 3) as $product) {
            $this->table(
                ['Field', 'Value'],
                collect($product)->map(fn ($v, $k) => [$k, $v ?? '(null)'])->values()->all()
            );
        }

        if ($count === 0) {
            $this->warn('No products found. Selectors may need updating in ProductPageParser.');
        }
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter ScrapeDiscoverCommandTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Commands/ScrapeDiscoverCommand.php tests/Feature/Commands/ScrapeDiscoverCommandTest.php
git commit -m "Add scrape:discover command for site exploration"
```

---

### Task 14: Create scrape:products command (thin)

Now the command is thin — delegates to `ProductPageParser` and `UpsertProduct`.

**Files:**
- Create: `app/Commands/ScrapeProductsCommand.php`
- Test: `tests/Feature/Commands/ScrapeProductsCommandTest.php`

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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter ScrapeProductsCommandTest`

Expected: FAIL — command not found.

**Step 3: Write the thin command**

```php
<?php

namespace App\Commands;

use App\Actions\UpsertProduct;
use App\Http\Integrations\WholeFoods\Requests\GetProductsRequest;
use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use App\Models\Category;
use App\Models\ScrapeLog;
use App\Support\ProductPageParser;
use LaravelZero\Framework\Commands\Command;

class ScrapeProductsCommand extends Command
{
    protected $signature = 'scrape:products
        {--category= : Scrape a single category by slug}
        {--force : Ignore freshness interval}
        {--limit=10 : Max pages per category}';

    protected $description = 'Scrape products from Whole Foods';

    public function handle(
        WholeFoodsConnector $connector,
        ProductPageParser $parser,
        UpsertProduct $upsertProduct,
    ): void {
        $categories = $this->categories();

        if ($categories->isEmpty()) {
            $this->warn('No categories found. Run seed:categories first.');

            return;
        }

        foreach ($categories as $category) {
            $this->scrapeCategory($connector, $parser, $upsertProduct, $category);
        }
    }

    protected function scrapeCategory(
        WholeFoodsConnector $connector,
        ProductPageParser $parser,
        UpsertProduct $upsertProduct,
        Category $category,
    ): void {
        if (! $this->option('force') && ! $category->needsScraping()) {
            $this->line("Skipping {$category->name} — still fresh.");

            return;
        }

        $this->info("Scraping {$category->name}...");

        $startTime = now();
        $created = 0;
        $updated = 0;
        $found = 0;
        $errors = 0;
        $errorDetails = [];
        $limit = (int) $this->option('limit');

        for ($page = 1; $page <= $limit; $page++) {
            $this->line("  Page {$page}...");

            $response = $connector->send(new GetProductsRequest($category->slug, $page));

            if ($response->failed()) {
                $errors++;
                $errorDetails[] = ['page' => $page, 'status' => $response->status()];
                $this->error("  HTTP {$response->status()} on page {$page}");

                break;
            }

            $products = $parser->parse($response->body());

            if (empty($products)) {
                $this->line('  No products found — end of pages.');

                break;
            }

            $found += count($products);

            foreach ($products as $productData) {
                $upsertProduct->execute($category, $productData) === 'created'
                    ? $created++
                    : $updated++;
            }

            $category->update(['last_page_scraped' => $page]);

            $this->applyDelay();
        }

        $category->update(['last_scraped_at' => now()]);

        ScrapeLog::create([
            'category_id' => $category->id,
            'command' => 'scrape:products',
            'products_found' => $found,
            'products_created' => $created,
            'products_updated' => $updated,
            'errors' => $errors,
            'error_details' => $errorDetails ?: null,
            'duration_seconds' => $startTime->diffInSeconds(now()),
            'created_at' => now(),
        ]);

        $this->info("  Done: {$created} created, {$updated} updated, {$errors} errors.");
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Category> */
    protected function categories(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Category::query();

        if ($slug = $this->option('category')) {
            $query->bySlug($slug);
        }

        return $query->get();
    }

    protected function applyDelay(): void
    {
        $base = (float) config('scraping.delay.initial_seconds', 5);
        $jitter = (float) config('scraping.delay.jitter_max_seconds', 3);
        $total = $base + (mt_rand(0, (int) ($jitter * 1000)) / 1000);

        usleep((int) ($total * 1_000_000));
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter ScrapeProductsCommandTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Commands/ScrapeProductsCommand.php tests/Feature/Commands/ScrapeProductsCommandTest.php
git commit -m "Add thin scrape:products command"
```

---

### Task 15: Create enrich:nutrition command (thin)

Thin command — delegates to `NutritionResolver` and `EnrichProductNutrition`.

**Files:**
- Create: `app/Commands/EnrichNutritionCommand.php`
- Test: `tests/Feature/Commands/EnrichNutritionCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
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
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter EnrichNutritionCommandTest`

Expected: FAIL — command not found.

**Step 3: Write the thin command**

```php
<?php

namespace App\Commands;

use App\Actions\EnrichProductNutrition;
use App\Models\Product;
use App\Support\NutritionResolver;
use LaravelZero\Framework\Commands\Command;

class EnrichNutritionCommand extends Command
{
    protected $signature = 'enrich:nutrition
        {--force : Re-enrich products that already have nutrition}
        {--limit=100 : Max products to enrich per run}';

    protected $description = 'Enrich products with nutrition data from Open Food Facts and USDA';

    public function handle(NutritionResolver $resolver, EnrichProductNutrition $enrichAction): void
    {
        $products = $this->option('force')
            ? Product::limit((int) $this->option('limit'))->get()
            : Product::needsNutrition()->limit((int) $this->option('limit'))->get();

        if ($products->isEmpty()) {
            $this->info('All products already have nutrition data.');

            return;
        }

        $this->info("Enriching {$products->count()} products...");
        $enriched = 0;
        $failed = 0;

        foreach ($products as $product) {
            $this->line("  {$product->name}...");

            $data = $resolver->resolve($product);

            if ($data) {
                $enrichAction->execute($product, $data);
                $this->info("    Saved from {$data['source']}: {$data['calories']} cal");
                $enriched++;
            } else {
                $this->warn('    No nutrition data found.');
                $failed++;
            }
        }

        $this->info("Done: {$enriched} enriched, {$failed} not found.");
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter EnrichNutritionCommandTest`

Expected: PASS

**Step 5: Commit**

```bash
git add app/Commands/EnrichNutritionCommand.php tests/Feature/Commands/EnrichNutritionCommandTest.php
git commit -m "Add thin enrich:nutrition command"
```

---

### Task 16: Create scrape:update convenience command

**Files:**
- Create: `app/Commands/ScrapeUpdateCommand.php`
- Test: `tests/Feature/Commands/ScrapeUpdateCommandTest.php`

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

    MockClient::global([
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
git add app/Commands/ScrapeUpdateCommand.php tests/Feature/Commands/ScrapeUpdateCommandTest.php
git commit -m "Add scrape:update convenience command"
```

---

### Task 17: Clean up, .env, and full test suite

**Files:**
- Create: `.env`
- Modify: `app/Crawlers/ProductCrawlObserver.php` (remove — replaced by ProductPageParser)

**Step 1: Create .env**

```
USDA_API_KEY=
```

**Step 2: Delete obsolete ProductCrawlObserver**

The `ProductCrawlObserver` has been replaced by `ProductPageParser`. Delete `app/Crawlers/ProductCrawlObserver.php` and the corresponding test `tests/Feature/ProductCrawlerTest.php`. Also delete `tests/Feature/HttpScrapingTest.php` — the Http facade test is a scaffold placeholder.

**Step 3: Run full test suite**

Run: `./vendor/bin/pest`

Expected: All tests pass.

**Step 4: Run Pint**

Run: `./vendor/bin/pint --dirty`

**Step 5: Commit**

```bash
git add -A
git commit -m "Clean up obsolete files, add .env, format with Pint"
```

---

### Task 18: Run scrape:discover against the live site

Manual step — not automated. After all code is in place:

1. Run: `php wholefoods-data migrate`
2. Run: `php wholefoods-data seed:categories`
3. Run: `php wholefoods-data scrape:discover --category=produce`

Review the output. If selectors don't match, update `ProductPageParser` selectors and re-run. This may take a few iterations.
