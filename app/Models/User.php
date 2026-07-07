<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password',
        'avatar', 'status', 'phone',
        'address', 'city', 'province', 'postal_code',
        'timezone', 'two_factor_enabled', 'stripe_connect_account_id',
        'stripe_connect_status', 'stripe_customer_id', 'stripe_identity_session_id',
        'identity_status', 'identity_verified_at', 'trust_score',
        'completed_payments_count', 'disputed_payments_count',
        'username', 'notif_member_joined', 'notif_payment_received',
        'notif_renewal_reminder', 'notif_payment_failed',
        'locale', 'currency', 'show_real_name', 'allow_direct_contact',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'identity_verified_at' => 'datetime',
            'trust_score' => 'decimal:2',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->show_real_name === false && $this->username) {
            return '@' . $this->username;
        }
        return $this->name;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sentInvitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    public function calculateTrustScore(): float
    {
        $score = 0;

        if ($this->identity_status === 'verified') {
            $score += 30;
        }

        if ($this->stripe_connect_status === 'active') {
            $score += 20;
        }

        $paymentsReceived = Payment::whereHas('group', fn($q) => $q->where('owner_id', $this->id))
            ->where('status', 'completed')
            ->count();

        if ($paymentsReceived > 0) {
            $score += 20;
        }

        $disputes = Dispute::whereHas('group', fn($q) => $q->where('owner_id', $this->id))
            ->where('status', 'resolved_refund')
            ->count();

        if ($disputes === 0) {
            $score += 15;
        }

        if ($this->created_at->diffInDays(now()) >= 30) {
            $score += 10;
        }

        if ($this->avatar) {
            $score += 5;
        }

        $score -= ($disputes * 20);

        $autoRefunds = Payment::whereHas('group', fn($q) => $q->where('owner_id', $this->id))
            ->where('refund_reason', 'auto_no_credentials')
            ->count();

        $score -= ($autoRefunds * 10);

        return max(0, min(100, $score));
    }

    public function getTrustScoreAttribute(): float
    {
        return $this->calculateTrustScore();
    }
}
