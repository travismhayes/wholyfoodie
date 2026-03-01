<?php

use App\Support\ProductPageParser;

it('parses product tiles from html', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Bananas</h2>
            <span class="w-pie--product-tile__brand">365 by Whole Foods Market</span>
            <span data-testid="product-tile-price">$0.29</span>
            <span data-testid="product-tile-unit">/ ea</span>
            <a href="/products/bananas-123">Link</a>
            <img src="https://cdn.example.com/bananas.jpg" />
        </div>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 class="w-cms--font-body__sans-bold">Organic Avocados</h2>
            <span class="w-pie--product-tile__brand">Whole Foods Market</span>
            <span data-testid="product-tile-price">$1.99</span>
            <span data-testid="product-tile-unit">/ ea</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products)->toHaveCount(2);

    expect($products[0])->toMatchArray([
        'name' => 'Organic Bananas',
        'brand' => '365 by Whole Foods Market',
        'price' => '0.29',
        'unit' => '/ ea',
        'url' => '/products/bananas-123',
        'image_url' => 'https://cdn.example.com/bananas.jpg',
    ]);

    expect($products[1]['name'])->toBe('Organic Avocados');
    expect($products[1]['price'])->toBe('1.99');
});

it('returns empty array for html with no products', function () {
    $html = '<html><body><p>No products here</p></body></html>';

    $parser = new ProductPageParser;

    expect($parser->parse($html))->toBeEmpty();
});

it('skips tiles with no product name', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <span data-testid="product-tile-price">$0.29</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;

    expect($parser->parse($html))->toBeEmpty();
});

it('extracts price as numeric string without dollar sign', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2>Salmon Fillet</h2>
            <span data-testid="product-tile-price">$12.99 /lb</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products[0]['price'])->toBe('12.99');
});
