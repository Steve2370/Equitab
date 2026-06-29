<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripePrice extends Model
{
    protected $fillable = [
        'group_id', 'stripe_price_id', 'stripe_product_id',
        'unit_amount', 'currency',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
