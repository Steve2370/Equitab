<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMember>
 */
class GroupMemberFactory extends Factory
{
    protected $model = GroupMember::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'user_id' => User::factory(),
            'role' => 'member',
            'status' => 'active',
            'share_amount' => 500,
            'joined_at' => now()->toDateString(),
            'last_payment_at' => null,
            'next_payment_at' => null,
            'stripe_subscription_id' => null,
            'stripe_subscription_item_id' => null,
            'stripe_customer_id' => null,
            'subscription_status' => null,
            'current_period_end' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => 'owner']);
    }

    public function pendingPayment(): static
    {
        return $this->state(fn () => ['status' => 'pending_payment']);
    }

    public function withStripeSubscription(string $subscriptionId = 'sub_fake123'): static
    {
        return $this->state(fn () => [
            'stripe_subscription_id' => $subscriptionId,
            'subscription_status' => 'active',
        ]);
    }
}
