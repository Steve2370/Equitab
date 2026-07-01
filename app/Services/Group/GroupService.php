<?php

namespace App\Services\Group;

use App\Models\Group;
use App\Models\User;
use App\Models\StripePrice;
use App\Repositories\Contracts\GroupRepositoryInterface;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class GroupService
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function create(User $owner, array $data): Group
    {
        return DB::transaction(function () use ($owner, $data) {
            $group = $this->groupRepository->create([
                ...$data,
                'owner_id' => $owner->id,
                'current_members' => 1,
                'status' => 'open',
                'uuid' => Str::uuid(),
            ]);

            $group->load('subscription');

            $stripeData = $this->gateway->createProduct($group);

            StripePrice::create([
                'group_id' => $group->id,
                'stripe_price_id' => null,
                'stripe_product_id' => $stripeData['product_id'],
                'unit_amount' => $group->total_price,
                'currency' => $group->subscription->currency,
            ]);

            $group->members()->create([
                'user_id' => $owner->id,
                'role' => 'owner',
                'status' => 'active',
                'share_amount' => $group->calculateCurrentPricePerMember(),
                'joined_at' => now(),
            ]);

            if (in_array($data['visibility'] ?? 'public', ['invite_only', 'private'])) {
                $token = bin2hex(random_bytes(16));
                \Illuminate\Support\Facades\Log::info('Generating invite token', ['visibility' => $data['visibility'], 'token' => $token]);
                $group->update(['invite_token' => $token]);
            }

            return $group;
        });
    }

    public function join(User $user, Group $group): void
    {
        DB::transaction(function () use ($user, $group) {
            if ($group->isFull()) {
                throw new Exception('Ce groupe est complet.');
            }

            $alreadyMember = $group->members()
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyMember) {
                throw new Exception('Vous êtes déjà membre de ce groupe.');
            }

            $group->members()->create([
                'user_id' => $user->id,
                'role' => 'member',
                'status' => 'pending_payment',
                'share_amount' => $group->price_per_member,
                'joined_at' => now(),
                'next_payment_at' => now()->addDays(3),
            ]);

            $group->increment('current_members');

            if ($group->fresh()->isFull()) {
                $this->groupRepository->update($group, ['status' => 'full']);
            }
        });
    }

    public function leave(User $user, Group $group): void
    {
        DB::transaction(function () use ($user, $group) {
            $member = $group->members()
                ->where('user_id', $user->id)
                ->where('role', 'member')
                ->firstOrFail();

            $member->update(['status' => 'left']);
            $group->decrement('current_members');

            if ($group->status === 'full') {
                $this->groupRepository->update($group, ['status' => 'open']);
            }
        });
    }
}
