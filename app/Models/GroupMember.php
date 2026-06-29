<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    protected $fillable = [
        'group_id', 'user_id', 'role', 'status',
        'share_amount', 'joined_at',
        'last_payment_at', 'next_payment_at',
    ];

    protected function casts(): array
    {
        return [
            'share_amount' => 'integer',
            'joined_at' => 'date',
            'last_payment_at' => 'date',
            'next_payment_at' => 'date',
        ];
    }

    public function getShareInDollarsAttribute(): float
    {
        return $this->share_amount / 100;
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
