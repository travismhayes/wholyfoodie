<?php

namespace App\Http\Integrations\Usda\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class SearchFoodsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $searchQuery, protected int $pageSize = 5) {}

    public function resolveEndpoint(): string
    {
        return '/foods/search';
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return [
            'query' => $this->searchQuery,
            'pageSize' => $this->pageSize,
            'dataType' => 'SR Legacy,Foundation',
        ];
    }
}
