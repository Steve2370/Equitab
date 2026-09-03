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
        // Ajoutées par les migrations add_stripe_subscription_fields_to_group_members_table
        // et add_stripe_item_id_to_group_members_table mais jamais reportées ici : Eloquent
        // rejette silencieusement (pas d'erreur) toute clé absente de $fillable lors d'un
        // create()/update()/updateOrCreate() — stripe_subscription_id ne s'enregistrait donc
        // JAMAIS sur le membre, même quand Stripe confirmait l'abonnement. C'est la cause
        // racine des membres bloqués "en attente" malgré un paiement Stripe réussi : le
        // webhook et la réconciliation cherchent le membre par stripe_subscription_id, qui
        // était toujours vide.
        'stripe_subscription_id', 'stripe_subscription_item_id',
        'stripe_customer_id', 'subscription_status', 'current_period_end',
    ];

    protected function casts(): array
    {
        return [
            'share_amount' => 'integer',
            'joined_at' => 'date',
            'last_payment_at' => 'date',
            'next_payment_at' => 'date',
            'current_period_end' => 'datetime',
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
