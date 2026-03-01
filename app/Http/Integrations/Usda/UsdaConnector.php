<?php

namespace App\Http\Integrations\Usda;

use Saloon\Http\Connector;

class UsdaConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.nal.usda.gov/fdc/v1';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return [
            'api_key' => config('scraping.usda.api_key', ''),
        ];
    }
}
