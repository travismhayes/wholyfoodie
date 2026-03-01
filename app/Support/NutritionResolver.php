<?php

namespace App\Support;

use App\Http\Integrations\OpenFoodFacts\OpenFoodFactsConnector;
use App\Http\Integrations\OpenFoodFacts\Requests\SearchProductsRequest;
use App\Http\Integrations\Usda\Requests\SearchFoodsRequest;
use App\Http\Integrations\Usda\UsdaConnector;
use App\Models\Product;
use Illuminate\Support\Collection;

class NutritionResolver
{
    public function __construct(
        protected OpenFoodFactsConnector $offConnector,
        protected UsdaConnector $usdaConnector,
    ) {}

    /**
     * @return array{
     *     calories: float|null,
     *     protein_g: float|null,
     *     fat_g: float|null,
     *     carbs_g: float|null,
     *     fiber_g: float|null,
     *     sugar_g: float|null,
     *     sodium_mg: float|null,
     *     serving_size: string|null,
     *     source: string
     * }|null
     */
    public function resolve(Product $product): ?array
    {
        return $this->tryOpenFoodFacts($product)
            ?? $this->tryUsda($product);
    }

    /**
     * @return array{
     *     calories: float|null,
     *     protein_g: float|null,
     *     fat_g: float|null,
     *     carbs_g: float|null,
     *     fiber_g: float|null,
     *     sugar_g: float|null,
     *     sodium_mg: float|null,
     *     serving_size: string|null,
     *     source: string
     * }|null
     */
    protected function tryOpenFoodFacts(Product $product): ?array
    {
        $searchTerm = trim("{$product->brand} {$product->name}");
        $response = $this->offConnector->send(new SearchProductsRequest($searchTerm, 3));

        if ($response->failed()) {
            return null;
        }

        $products = $response->json('products', []);

        if (empty($products)) {
            return null;
        }

        $nutriments = $products[0]['nutriments'] ?? [];

        return [
            'calories' => $this->floatOrNull($nutriments['energy-kcal_serving'] ?? $nutriments['energy-kcal_100g'] ?? null),
            'protein_g' => $this->floatOrNull($nutriments['proteins_serving'] ?? $nutriments['proteins_100g'] ?? null),
            'fat_g' => $this->floatOrNull($nutriments['fat_serving'] ?? $nutriments['fat_100g'] ?? null),
            'carbs_g' => $this->floatOrNull($nutriments['carbohydrates_serving'] ?? $nutriments['carbohydrates_100g'] ?? null),
            'fiber_g' => $this->floatOrNull($nutriments['fiber_serving'] ?? $nutriments['fiber_100g'] ?? null),
            'sugar_g' => $this->floatOrNull($nutriments['sugars_serving'] ?? $nutriments['sugars_100g'] ?? null),
            'sodium_mg' => $this->sodiumToMg($nutriments['sodium_serving'] ?? $nutriments['sodium_100g'] ?? null),
            'serving_size' => $products[0]['serving_size'] ?? null,
            'source' => 'open_food_facts',
        ];
    }

    /**
     * @return array{
     *     calories: float|null,
     *     protein_g: float|null,
     *     fat_g: float|null,
     *     carbs_g: float|null,
     *     fiber_g: float|null,
     *     sugar_g: float|null,
     *     sodium_mg: float|null,
     *     serving_size: string|null,
     *     source: string
     * }|null
     */
    protected function tryUsda(Product $product): ?array
    {
        $response = $this->usdaConnector->send(new SearchFoodsRequest($product->name, 3));

        if ($response->failed()) {
            return null;
        }

        $foods = $response->json('foods', []);

        if (empty($foods)) {
            return null;
        }

        $match = $foods[0];
        $nutrients = collect($match['foodNutrients'] ?? []);

        $servingSizeUnit = $match['servingSizeUnit'] ?? 'g';
        $servingSize = isset($match['servingSize'])
            ? "{$match['servingSize']}{$servingSizeUnit}"
            : null;

        return [
            'calories' => $this->nutrient($nutrients, 'Energy'),
            'protein_g' => $this->nutrient($nutrients, 'Protein'),
            'fat_g' => $this->nutrient($nutrients, 'Total lipid (fat)'),
            'carbs_g' => $this->nutrient($nutrients, 'Carbohydrate, by difference'),
            'fiber_g' => $this->nutrient($nutrients, 'Fiber, total dietary'),
            'sugar_g' => $this->nutrient($nutrients, 'Sugars, total including NLEA'),
            'sodium_mg' => $this->nutrient($nutrients, 'Sodium, Na'),
            'serving_size' => $servingSize,
            'source' => 'usda',
        ];
    }

    /** @param Collection<int, array{nutrientName: string, value: float, unitName: string}> $nutrients */
    protected function nutrient(Collection $nutrients, string $name): ?float
    {
        $match = $nutrients->firstWhere('nutrientName', $name);

        return $match ? (float) $match['value'] : null;
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }

    protected function sodiumToMg(mixed $sodiumGrams): ?float
    {
        if ($sodiumGrams === null) {
            return null;
        }

        return (float) $sodiumGrams * 1000;
    }
}
