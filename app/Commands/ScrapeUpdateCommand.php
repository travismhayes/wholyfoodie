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
