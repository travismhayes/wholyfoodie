<?php

namespace App\Actions;

use App\Models\Nutrition;
use App\Models\Product;

class EnrichProductNutrition
{
    /**
     * @param array{
     *     calories: float|null,
     *     protein_g: float|null,
     *     fat_g: float|null,
     *     carbs_g: float|null,
     *     fiber_g: float|null,
     *     sugar_g: float|null,
     *     sodium_mg: float|null,
     *     serving_size: string|null,
     *     source: string
     * } $data
     */
    public function execute(Product $product, array $data): void
    {
        Nutrition::updateOrCreate(
            ['product_id' => $product->id],
            $data,
        );
    }
}
