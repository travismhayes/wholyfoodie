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
