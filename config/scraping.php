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
