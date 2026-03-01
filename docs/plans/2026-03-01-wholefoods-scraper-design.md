# Whole Foods Product Scraper Design

## Goal

Scrape Whole Foods Market product data (name, brand, price, size) and enrich with nutrition/macro data (calories, protein, fat, carbs, fiber) from free APIs. Store everything in a local SQLite database for meal planning.

## Target Categories

- Meat & Poultry
- Seafood
- Produce (fruits & vegetables)
- Dairy & Eggs
- Bread & Bakery
- Frozen Foods
- Pantry staples (rice, beans, canned goods, oils, etc.)

## Data Model

### categories

| Column | Type | Notes |
|--------|------|-------|
| id | integer (PK) | |
| name | string | e.g., "Meat & Poultry" |
| slug | string | URL slug for scraping |
| last_scraped_at | timestamp (nullable) | |
| last_page_scraped | integer (default 0) | For resumability |
| created_at | timestamp | |
| updated_at | timestamp | |

### products

| Column | Type | Notes |
|--------|------|-------|
| id | integer (PK) | |
| category_id | foreign key | |
| name | string | |
| brand | string (nullable) | |
| asin | string (nullable) | Amazon product ID |
| barcode | string (nullable) | UPC if available |
| price | decimal(8,2) (nullable) | Current price |
| unit | string (nullable) | e.g., "16 oz", "1 lb" |
| image_url | string (nullable) | |
| whole_foods_url | string (nullable) | |
| last_scraped_at | timestamp (nullable) | |
| created_at | timestamp | |
| updated_at | timestamp | |

### nutrition

| Column | Type | Notes |
|--------|------|-------|
| id | integer (PK) | |
| product_id | foreign key (unique) | One-to-one with products |
| calories | decimal(8,2) (nullable) | Per serving |
| protein_g | decimal(8,2) (nullable) | |
| fat_g | decimal(8,2) (nullable) | |
| carbs_g | decimal(8,2) (nullable) | |
| fiber_g | decimal(8,2) (nullable) | |
| sugar_g | decimal(8,2) (nullable) | |
| sodium_mg | decimal(8,2) (nullable) | |
| serving_size | string (nullable) | e.g., "1 cup (240ml)" |
| source | string | open_food_facts, usda, manual |
| created_at | timestamp | |
| updated_at | timestamp | |

### scrape_logs

| Column | Type | Notes |
|--------|------|-------|
| id | integer (PK) | |
| category_id | foreign key (nullable) | |
| command | string | Which command ran |
| products_found | integer (default 0) | |
| products_created | integer (default 0) | |
| products_updated | integer (default 0) | |
| errors | integer (default 0) | |
| error_details | json (nullable) | HTTP status codes, messages |
| duration_seconds | integer (nullable) | |
| created_at | timestamp | |

## Architecture

### Saloon v3 Connectors

All HTTP communication goes through Saloon connectors with built-in rate limiting and retry logic.

**WholeFoodsConnector** (existing, needs update):
- Base URL TBD — first task is to discover the actual API endpoints the Next.js site calls
- If no usable JSON endpoints found, falls back to Spatie Crawler for HTML scraping
- Uses `HasRateLimits` trait pulling limits from `config/scraping.php`
- Retry logic via `$tries` and `handleRetry` for 429/5xx responses

**OpenFoodFactsConnector** (new):
- Base URL: `https://world.openfoodfacts.org`
- Requests: search by product name/brand, lookup by barcode
- No auth needed, free API

**UsdaConnector** (new):
- Base URL: `https://api.nal.usda.gov/fdc/v1`
- Requests: food search by name
- Auth: API key from `.env` (`USDA_API_KEY`)
- Rate limit: 3,600 requests/hour

### Spatie Crawler + Symfony DomCrawler (fallback)

- `ProductCrawlObserver` (existing, needs expansion) for HTML parsing
- Used if Whole Foods doesn't expose usable JSON endpoints
- DomCrawler extracts product data from rendered pages

### Discovery Step (must happen first)

Before building the full pipeline, manually inspect Whole Foods site network requests to find:
- JSON API endpoints the Next.js frontend calls
- Request headers/cookies required
- Pagination patterns
- Whether a headless browser is actually needed or if direct HTTP works

## Commands

### scrape:discover (development/exploration)

Hits the Whole Foods product pages and inspects responses to determine:
- Whether JSON endpoints exist that Saloon can call directly
- What HTML structure the product pages use (for DomCrawler selectors)
- What pagination looks like
- Outputs findings to the console for us to review

### scrape:products

Main scraper command. For each enabled category:
1. Check if category needs scraping (based on `last_scraped_at` and config interval)
2. Fetch product listing pages via Saloon or Spatie Crawler
3. Extract: name, brand, price, unit/size, ASIN, image URL, product URL
4. Upsert into `products` table (update price if changed, create if new)
5. Log results to `scrape_logs`

Options:
- `--category=` to scrape a single category
- `--force` to ignore the freshness interval
- `--limit=` to cap number of pages per category

### enrich:nutrition

For each product missing nutrition data:
1. Try Open Food Facts — search by barcode first, then by name + brand
2. If no match, try USDA FoodData Central — search by product name
3. Save to `nutrition` table with source tracked
4. Skip products that already have nutrition data

Options:
- `--source=` to only use a specific source (open_food_facts, usda)
- `--force` to re-enrich products that already have nutrition data
- `--limit=` to cap number of products to enrich per run

### scrape:update

Convenience command that runs the incremental workflow:
1. `scrape:products` for categories older than the configured freshness interval
2. `enrich:nutrition` for any new products found

## Configuration (config/scraping.php)

```php
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

Note: Category slugs are placeholders — exact values will be confirmed during the discovery step.

## Nutrition Data Sources

### Open Food Facts (primary, free)

- ~1,500+ Whole Foods "365" brand products with full nutrition
- Search by barcode: `GET /api/v2/product/{barcode}`
- Search by name: `GET /cgi/search.pl?search_terms={query}&json=1`
- Best for: branded/packaged items

### USDA FoodData Central (fallback, free)

- Comprehensive generic food nutrition data
- Search: `GET /fdc/v1/foods/search?query={name}&api_key={key}`
- Best for: produce, raw meats, dairy, generic items like "chicken breast" or "broccoli"
- Requires free API key signup at fdc.nal.usda.gov

## Rate Limiting & Resilience

- Saloon `HasRateLimits` trait enforces requests-per-minute from config
- Random jitter added to delays to avoid fixed-interval patterns
- 429 responses trigger exponential backoff (30s, 60s, 120s, up to 5min)
- 5xx responses retry once after 10s then skip and log
- Scrape runs are resumable via `last_scraped_at` and `last_page_scraped` on categories
- All errors logged to `scrape_logs` with HTTP status and details

## Implementation Order

1. Config file (`config/scraping.php`)
2. Database migrations (categories, products, nutrition, scrape_logs)
3. Seed categories from config
4. `scrape:discover` command — explore the Whole Foods site to find endpoints
5. Update `WholeFoodsConnector` with discovered endpoints + rate limiting
6. `scrape:products` command
7. `OpenFoodFactsConnector` + `UsdaConnector`
8. `enrich:nutrition` command
9. `scrape:update` convenience command
10. Tests with `Http::fake()` / Saloon's `MockClient`
