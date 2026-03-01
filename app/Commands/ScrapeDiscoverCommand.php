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
        $this->line('Body length: '.strlen($response->body()).' bytes');

        if (str_contains($response->header('Content-Type') ?? '', 'json')) {
            $this->info('Response is JSON — API endpoint found!');
            $this->line(json_encode(json_decode($response->body()), JSON_PRETTY_PRINT));

            return;
        }

        $products = $parser->parse($response->body());
        $count = count($products);
        $this->info("Found {$count} products with current selectors.");

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
