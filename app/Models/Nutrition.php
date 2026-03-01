<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nutrition extends Model
{
    protected $table = 'nutrition';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'calories' => 'decimal:2',
            'protein_g' => 'decimal:2',
            'fat_g' => 'decimal:2',
            'carbs_g' => 'decimal:2',
            'fiber_g' => 'decimal:2',
            'sugar_g' => 'decimal:2',
            'sodium_mg' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
