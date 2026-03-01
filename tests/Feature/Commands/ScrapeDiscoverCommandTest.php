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
