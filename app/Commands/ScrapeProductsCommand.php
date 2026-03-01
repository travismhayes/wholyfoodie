<?php

namespace App\Commands;

use App\Actions\UpsertProduct;
use App\Http\Integrations\WholeFoods\Requests\GetProductsRequest;
use App\Http\Integrations\WholeFoods\WholeFoodsConnector;
use App\Models\Category;
use App\Models\ScrapeLog;
use App\Support\ProductPageParser;
use Illuminate\Database\Eloquent\Collection;
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

    /** @return Collection<int, Category> */
    protected function categories(): Collection
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
