<?php

namespace App\Http\Integrations\WholeFoods\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetProductsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $category = '', protected int $page = 1) {}

    public function resolveEndpoint(): string
    {
        return "/products/{$this->category}";
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return [
            'page' => $this->page,
        ];
    }
}
