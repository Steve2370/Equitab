<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'category_id', 'name', 'logo', 'website',
        'max_members', 'monthly_price', 'currency',
        'billing_cycle', 'is_active', 'is_verified',
        'tier', 'slug',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    public function getPriceInDollarsAttribute(): float
    {
        return $this->monthly_price / 100;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCategory::class, 'category_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
