<?php

namespace App\Http\Integrations\OpenFoodFacts\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetProductByBarcodeRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(protected string $barcode) {}

    public function resolveEndpoint(): string
    {
        return "/api/v2/product/{$this->barcode}";
    }
}
