<?php

use App\Support\ProductPageParser;

it('parses product tiles from html', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <a class="w-pie--product-tile__link" data-csa-c-content-id="ASIN: B078J118FH" href="/products/bananas-123">
                <div class="w-pie--product-tile__image">
                    <img alt="Organic Bananas" data-src="https://cdn.example.com/bananas.jpg" src="/images/img-placeholder.svg" />
                </div>
                <div class="w-pie--product-tile__content">
                    <span data-testid="product-tile-brand">365 by Whole Foods Market</span>
                    <h2 data-testid="product-tile-name">Organic Bananas</h2>
                    <span data-testid="product-tile-price">$0.29</span>
                    <span data-testid="product-tile-unit">/ ea</span>
                </div>
            </a>
        </div>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <a class="w-pie--product-tile__link" data-csa-c-content-id="ASIN: B00MD2F34G" href="/products/avocados-456">
                <div class="w-pie--product-tile__content">
                    <span data-testid="product-tile-brand">Whole Foods Market</span>
                    <h2 data-testid="product-tile-name">Organic Avocados</h2>
                    <span data-testid="product-tile-price">$1.99</span>
                    <span data-testid="product-tile-unit">/ ea</span>
                </div>
            </a>
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
        'asin' => 'B078J118FH',
    ]);

    expect($products[1]['name'])->toBe('Organic Avocados');
    expect($products[1]['price'])->toBe('1.99');
    expect($products[1]['asin'])->toBe('B00MD2F34G');
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
            <h2 data-testid="product-tile-name">Salmon Fillet</h2>
            <span data-testid="product-tile-price">$12.99 /lb</span>
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products[0]['price'])->toBe('12.99');
});

it('prefers data-src over src for lazy-loaded images', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 data-testid="product-tile-name">Green Beans</h2>
            <img data-src="https://cdn.example.com/real.jpg" src="/images/img-placeholder.svg" />
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products[0]['image_url'])->toBe('https://cdn.example.com/real.jpg');
});

it('falls back to src when data-src is missing', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="w-pie--product-tile" data-testid="product-tile">
            <h2 data-testid="product-tile-name">Kale</h2>
            <img src="https://cdn.example.com/kale.jpg" />
        </div>
    </body></html>
    HTML;

    $parser = new ProductPageParser;
    $products = $parser->parse($html);

    expect($products[0]['image_url'])->toBe('https://cdn.example.com/kale.jpg');
});
