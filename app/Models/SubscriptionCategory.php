<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionCategory extends Model
{
    protected $fillable = [
        'name', 'icon', 'color',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'category_id');
    }
}
