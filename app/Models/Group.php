<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Group extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'subscription_id', 'owner_id', 'name',
        'description', 'max_members', 'current_members',
        'price_per_member', 'total_price', 'split_type', 'status',
        'visibility', 'renewal_date', 'auto_renew', 'settings',
        'credential_email', 'credential_password', 'credential_notes',
        'tier', 'invite_token',
    ];

    protected function casts(): array
    {
        return [
            'price_per_member' => 'integer',
            'auto_renew' => 'boolean',
            'renewal_date' => 'date',
            'settings' => 'array',
            'credential_email' => 'encrypted',
            'credential_password' => 'encrypted',
            'credential_notes' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Group $group) {
            $group->uuid ??= Str::uuid();
        });
    }

    public function getPriceInDollarsAttribute(): float
    {
        return $this->price_per_member / 100;
    }

    public function isFull(): bool
    {
        return $this->current_members >= $this->max_members;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class)->where('status', 'active');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function stripePrice(): HasOne
    {
        return $this->hasOne(StripePrice::class);
    }

    public function calculateCurrentPricePerMember(): int
    {
        $activeMembers = $this->members()->where('status', 'active')->count();

        if ($activeMembers === 0) {
            return $this->total_price;
        }

        return (int) round($this->total_price / $activeMembers);
    }

    /**
     * Prix par membre tel qu'il sera APRÈS qu'un nouveau membre ait rejoint —
     * à utiliser pour tout affichage destiné à quelqu'un qui n'a pas encore
     * rejoint le groupe (page d'invitation, liste publique des groupes).
     * Ne pas confondre avec calculateCurrentPricePerMember(), qui reflète le
     * prix réel des membres déjà présents (gains du propriétaire, etc.).
     */
    public function calculatePricePerMemberIfJoined(): int
    {
        $activeMembers = $this->members()->where('status', 'active')->count();

        return (int) round($this->total_price / ($activeMembers + 1));
    }

    public function calculateOwnerNetEarnings(): int
    {
        $pricePerMember = $this->calculateCurrentPricePerMember();
        $payingMembers = $this->members()->where('status', 'active')->where('role', 'member')->count();

        return $pricePerMember * $payingMembers;
    }
}
