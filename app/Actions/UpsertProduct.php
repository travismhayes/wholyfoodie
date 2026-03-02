<?php

namespace App\Actions;

use App\Models\Category;
use App\Models\Product;

class UpsertProduct
{
    /** @param array{name: string, brand: string|null, price: string|null, unit: string|null, url: string|null, image_url: string|null, asin: string|null} $data */
    public function execute(Category $category, array $data): string
    {
        $product = Product::where('category_id', $category->id)
            ->where('name', $data['name'])
            ->where('brand', $data['brand'])
            ->first();

        if ($product) {
            $product->update([
                'price' => $data['price'],
                'unit' => $data['unit'],
                'asin' => $data['asin'] ?? null,
                'whole_foods_url' => $data['url'],
                'image_url' => $data['image_url'],
                'last_scraped_at' => now(),
            ]);

            return 'updated';
        }

        Product::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'brand' => $data['brand'],
            'price' => $data['price'],
            'unit' => $data['unit'],
            'asin' => $data['asin'] ?? null,
            'whole_foods_url' => $data['url'],
            'image_url' => $data['image_url'],
            'last_scraped_at' => now(),
        ]);

        return 'created';
    }
}
