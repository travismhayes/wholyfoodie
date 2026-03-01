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
