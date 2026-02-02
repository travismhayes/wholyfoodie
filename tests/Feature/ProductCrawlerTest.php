<?php

use App\Crawlers\ProductCrawlObserver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Spatie\Crawler\Crawler;

it('crawls product pages and extracts data', function () {
    $html = <<<'HTML'
    <html>
    <body>
        <div class="product" data-id="123">
            <h1>Organic Apples</h1>
            <span class="price">$4.99</span>
        </div>
    </body>
    </html>
    HTML;

    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'text/html'], $html),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $client = new Client(['handler' => $handlerStack]);

    $observer = new ProductCrawlObserver;

    (new Crawler($client))
        ->ignoreRobots()
        ->setCrawlObserver($observer)
        ->setMaximumDepth(1)
        ->setTotalCrawlLimit(1)
        ->startCrawling('https://example.com/product');

    expect($observer->getProducts())->toHaveCount(1);
    expect($observer->getProducts()[0]['name'])->toBe('Organic Apples');
    expect($observer->getProducts()[0]['price'])->toBe('$4.99');
});
