<?php

namespace App\Http\Integrations\WholeFoods;

use Saloon\Http\Connector;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Stores\FileStore;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;

class WholeFoodsConnector extends Connector
{
    use HasRateLimits;

    public ?int $tries = 3;

    public ?int $retryInterval = 10000;

    public ?bool $useExponentialBackoff = true;

    public function resolveBaseUrl(): string
    {
        return 'https://www.wholefoodsmarket.com';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/json',
            'User-Agent' => config('scraping.user_agent', 'WholeFoodsData/1.0'),
        ];
    }

    /** @return array<int, Limit> */
    protected function resolveLimits(): array
    {
        return [
            Limit::allow(
                (int) config('scraping.rate_limit.requests_per_minute', 12)
            )->everyMinute(),
        ];
    }

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return new FileStore(storage_path('rate-limits'));
    }
}
