<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = [
        'uuid', 'group_id', 'user_id', 'transaction_id',
        'amount', 'currency', 'status',
        'period_start', 'period_end', 'due_date',
        'paid_at', 'retry_count',
        // Même bug que GroupMember : ces colonnes existent depuis
        // add_stripe_fields_to_payments_table / add_refund_fields_to_payments_table
        // mais n'avaient jamais été ajoutées ici, donc Eloquent les
        // ignorait silencieusement — platform_fee_amount (les gains
        // Equitab affichés dans l'admin) et stripe_payment_intent_id
        // (utilisé pour la déduplication et les remboursements) ne
        // s'enregistraient jamais.
        'stripe_payment_intent_id', 'stripe_transfer_id', 'platform_fee_amount',
        'refunded_at', 'refund_reason', 'stripe_refund_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'platform_fee_amount' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->uuid ??= Str::uuid();
        });
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }

    public function getAmountInDollarsAttribute(): float
    {
        return $this->amount / 100;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
