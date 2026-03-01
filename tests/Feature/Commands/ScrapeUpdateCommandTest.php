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

    config()->set('scraping.delay.initial_seconds', 0);
    config()->set('scraping.delay.jitter_max_seconds', 0);
});

it('runs scrape and enrich in sequence', function () {
    Category::create(['name' => 'Produce', 'slug' => 'produce']);

    $this->artisan('scrape:update')
        ->assertExitCode(0);
});
