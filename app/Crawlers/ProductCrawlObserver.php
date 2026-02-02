<?php

namespace App\Crawlers;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Symfony\Component\DomCrawler\Crawler;

class ProductCrawlObserver extends CrawlObserver
{
    /** @var array<int, array{id: string|null, name: string, price: string}> */
    protected array $products = [];

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $html = (string) $response->getBody();
        $crawler = new Crawler($html);

        $crawler->filter('.product')->each(function (Crawler $node) {
            $this->products[] = [
                'id' => $node->attr('data-id'),
                'name' => $node->filter('h1')->text(''),
                'price' => $node->filter('.price')->text(''),
            ];
        });
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        // Log failure if needed
    }

    /** @return array<int, array{id: string|null, name: string, price: string}> */
    public function getProducts(): array
    {
        return $this->products;
    }
}
