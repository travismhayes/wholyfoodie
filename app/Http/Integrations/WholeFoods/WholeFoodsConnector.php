<?php

namespace App\Http\Integrations\WholeFoods;

use Saloon\Http\Connector;

class WholeFoodsConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.wholefoods.com';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'WholeFoodsData/1.0',
        ];
    }
}
