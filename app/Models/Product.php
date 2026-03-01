<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'last_scraped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasOne<Nutrition, $this> */
    public function nutrition(): HasOne
    {
        return $this->hasOne(Nutrition::class);
    }

    /** @param Builder<self> $query */
    public function scopeNeedsNutrition(Builder $query): void
    {
        $query->whereDoesntHave('nutrition');
    }
}
