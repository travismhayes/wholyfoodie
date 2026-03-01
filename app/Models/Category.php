<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_scraped_at' => 'datetime',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function needsScraping(): bool
    {
        if (! $this->last_scraped_at) {
            return true;
        }

        $maxAgeDays = config('scraping.freshness.category_max_age_days', 7);

        return $this->last_scraped_at->diffInDays(now()) >= $maxAgeDays;
    }

    /** @param Builder<self> $query */
    public function scopeForScraping(Builder $query): void
    {
        $maxAgeDays = config('scraping.freshness.category_max_age_days', 7);

        $query->where(function (Builder $q) use ($maxAgeDays) {
            $q->whereNull('last_scraped_at')
                ->orWhere('last_scraped_at', '<=', now()->subDays($maxAgeDays));
        });
    }

    /** @param Builder<self> $query */
    public function scopeBySlug(Builder $query, string $slug): void
    {
        $query->where('slug', $slug);
    }
}
