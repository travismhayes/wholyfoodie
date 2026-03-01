<?php

namespace App\Http\Integrations\OpenFoodFacts\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class SearchProductsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $searchTerms, protected int $pageSize = 10) {}

    public function resolveEndpoint(): string
    {
        return '/cgi/search.pl';
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return [
            'search_terms' => $this->searchTerms,
            'action' => 'process',
            'json' => 1,
            'page_size' => $this->pageSize,
        ];
    }
}
