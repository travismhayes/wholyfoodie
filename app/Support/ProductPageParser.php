<?php

namespace App\Support;

use Symfony\Component\DomCrawler\Crawler;

class ProductPageParser
{
    protected string $tileSelector = '[data-testid="product-tile"]';

    protected string $nameSelector = '[data-testid="product-tile-name"]';

    protected string $brandSelector = '[data-testid="product-tile-brand"]';

    protected string $priceSelector = '[data-testid="product-tile-price"]';

    protected string $unitSelector = '[data-testid="product-tile-unit"]';

    /** @return array<int, array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null, asin: string|null}> */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $products = [];

        $crawler->filter($this->tileSelector)->each(function (Crawler $node) use (&$products) {
            $name = $this->text($node, $this->nameSelector);

            if (! $name) {
                return;
            }

            $products[] = [
                'name' => $name,
                'brand' => $this->text($node, $this->brandSelector),
                'price' => $this->price($node),
                'unit' => $this->text($node, $this->unitSelector),
                'url' => $this->attr($node, 'a', 'href'),
                'image_url' => $this->lazyImage($node),
                'asin' => $this->asin($node),
            ];
        });

        return $products;
    }

    protected function text(Crawler $node, string $selector): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? trim($filtered->text('')) : null;
    }

    protected function attr(Crawler $node, string $selector, string $attribute): ?string
    {
        $filtered = $node->filter($selector);

        return $filtered->count() > 0 ? $filtered->attr($attribute) : null;
    }

    protected function price(Crawler $node): ?string
    {
        $text = $this->text($node, $this->priceSelector);

        if (! $text) {
            return null;
        }

        preg_match('/[\d.]+/', $text, $matches);

        return $matches[0] ?? null;
    }

    protected function lazyImage(Crawler $node): ?string
    {
        $img = $node->filter('img');

        if ($img->count() === 0) {
            return null;
        }

        return $img->attr('data-src') ?? $img->attr('src');
    }

    protected function asin(Crawler $node): ?string
    {
        $link = $node->filter('a[data-csa-c-content-id]');

        if ($link->count() === 0) {
            return null;
        }

        $contentId = $link->attr('data-csa-c-content-id');

        if ($contentId && preg_match('/ASIN:\s*(\w+)/', $contentId, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
