<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'group_id' => Group::factory(),
            'user_id' => User::factory(),
            'transaction_id' => null,
            'amount' => 1999,
            'currency' => 'CAD',
            'status' => 'completed',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
            'retry_count' => 0,
            'stripe_payment_intent_id' => 'pi_' . Str::random(16),
            'stripe_transfer_id' => null,
            'platform_fee_amount' => 100,
            'refunded_at' => null,
            'refund_reason' => null,
            'stripe_refund_id' => null,
        ];
    }

    public function refunded(): static
    {
        return $this->state(fn () => [
            'status' => 'refunded',
            'refunded_at' => now(),
            'refund_reason' => 'dispute_resolved',
            'stripe_refund_id' => 're_' . Str::random(16),
        ]);
    }

    public function withoutStripeIntent(): static
    {
        return $this->state(fn () => ['stripe_payment_intent_id' => null]);
    }
}
