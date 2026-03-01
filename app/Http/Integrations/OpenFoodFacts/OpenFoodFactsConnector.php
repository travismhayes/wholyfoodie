<?php

namespace App\Http\Integrations\OpenFoodFacts;

use Saloon\Http\Connector;

class OpenFoodFactsConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://world.openfoodfacts.org';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => config('scraping.user_agent', 'WholeFoodsData/1.0'),
        ];
    }
}
