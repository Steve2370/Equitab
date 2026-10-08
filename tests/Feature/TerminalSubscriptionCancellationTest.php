<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingReconciliationService;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingTestCase;

class TerminalSubscriptionCancellationTest extends BillingTestCase
{
    public static function terminalSubscriptions(): array
    {
        return [
            'CAD canceled' => ['CAD', 'canceled'],
            'CAD incomplete_expired' => ['CAD', 'incomplete_expired'],
            'EUR canceled' => ['EUR', 'canceled'],
            'EUR incomplete_expired' => ['EUR', 'incomplete_expired'],
        ];
    }

    #[DataProvider('terminalSubscriptions')]
    public function test_closing_a_group_preserves_terminal_subscriptions_and_releases_each_seat_once(string $currency, string $terminal): void
    {
        $this->freezeTime();
        $group = Group::factory()->create(['currency' => $currency, 'max_members' => 2, 'current_members' => 2, 'status' => 'full']);
        $owner = GroupMember::factory()->for($group)->for($group->owner, 'user')->owner()->create();
        $member = GroupMember::factory()->for($group)->withStripeSubscription('sub_offline_terminal_close')
            ->create(['status' => 'pending_payment', 'subscription_status' => $terminal]);
        $this->mock(PaymentGatewayInterface::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));

        $this->assertTrue(app(GroupService::class)->close($group->owner, $group));
        $requestedAt = $member->fresh()->cancellation_requested_at;
        $this->assertNotNull($requestedAt);
        $this->assertSame('closed', $group->fresh()->status);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame($terminal, $member->fresh()->subscription_status);

        $this->travel(5)->minutes();
        $this->assertTrue(app(GroupService::class)->close($group->owner, $group));

        $this->assertSame('closed', $group->fresh()->status);
        $this->assertSame($currency, $group->fresh()->currency);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame($terminal, $member->fresh()->subscription_status);
        $this->assertSame('sub_offline_terminal_close', $member->fresh()->stripe_subscription_id);
        $this->assertTrue($requestedAt->equalTo($member->fresh()->cancellation_requested_at));
        $this->assertDatabaseCount('group_members', 2);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function currencies(): array
    {
        return [['CAD'], ['EUR']];
    }

    #[DataProvider('currencies')]
    public function test_dry_reconciliation_counts_only_pending_and_null_cancellations_without_mutation(string $currency): void
    {
        [$group, $members] = $this->cancellationFixtures($currency, 'left');
        $beforeGroup = $group->getAttributes();
        $beforeMembers = array_map(fn (GroupMember $member) => $member->getAttributes(), $members);
        $this->mock(PaymentGatewayInterface::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));

        $this->assertSame($this->counts(2), app(BillingReconciliationService::class)->reconcile(true));

        $this->assertSame($beforeGroup, $group->fresh()->getAttributes());
        foreach ($members as $index => $member) {
            $this->assertSame($beforeMembers[$index], $member->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('group_members', 5);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function retryableMemberships(): array
    {
        return [
            'CAD seats already released' => ['CAD', 'left'],
            'CAD seats still reserved' => ['CAD', 'pending_payment'],
            'EUR seats already released' => ['EUR', 'left'],
            'EUR seats still reserved' => ['EUR', 'pending_payment'],
        ];
    }

    #[DataProvider('retryableMemberships')]
    public function test_real_reconciliation_excludes_terminals_and_retries_pending_and_null_without_double_seat_release(string $currency, string $membershipStatus): void
    {
        [$group, $members] = $this->cancellationFixtures($currency, $membershipStatus);
        [$canceled, $expired, $pending, $unknown] = $members;
        $terminalRows = [$canceled->getAttributes(), $expired->getAttributes()];
        $requestedAt = $pending->cancellation_requested_at;
        $pendingCalls = 0;
        $this->mock(PaymentGatewayInterface::class, function ($mock) use ($pending, $unknown, &$pendingCalls): void {
            $mock->shouldReceive('cancelSubscription')->twice()->with($pending->stripe_subscription_id)
                ->andReturnUsing(function () use ($pending, &$pendingCalls): void {
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertSame('left', $pending->fresh()->status);
                    $this->assertSame('cancellation_pending', $pending->fresh()->subscription_status);
                    if (++$pendingCalls === 1) {
                        throw new \RuntimeException('Synthetic cancellation timeout');
                    }
                });
            $mock->shouldReceive('cancelSubscription')->once()->with($unknown->stripe_subscription_id)
                ->andReturnUsing(function () use ($unknown): void {
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertSame('left', $unknown->fresh()->status);
                    $this->assertSame('cancellation_pending', $unknown->fresh()->subscription_status);
                });
        });
        $service = app(BillingReconciliationService::class);

        $this->assertSame($this->counts(2, 1), $service->reconcile());
        $this->assertSame('cancellation_pending', $pending->fresh()->subscription_status);
        $this->assertSame('canceled', $unknown->fresh()->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame($this->counts(1), $service->reconcile(true));

        $this->travel(5)->minutes();
        $this->assertSame($this->counts(1), $service->reconcile());
        $this->assertSame($this->counts(0), $service->reconcile());
        $this->assertSame($this->counts(0), $service->reconcile(true));
        $this->assertSame(2, $pendingCalls);
        foreach ([$pending, $unknown] as $member) {
            $this->assertSame('left', $member->fresh()->status);
            $this->assertSame('canceled', $member->fresh()->subscription_status);
            $this->assertSame($member->stripe_subscription_id, $member->fresh()->stripe_subscription_id);
            $this->assertTrue($requestedAt->equalTo($member->fresh()->cancellation_requested_at));
        }
        $this->assertSame($terminalRows[0], $canceled->fresh()->getAttributes());
        $this->assertSame($terminalRows[1], $expired->fresh()->getAttributes());
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame($currency, $group->fresh()->currency);
        $this->assertDatabaseCount('group_members', 5);
        $this->assertDatabaseCount('payments', 0);
    }

    /** @return array{Group, list<GroupMember>} */
    private function cancellationFixtures(string $currency, string $membershipStatus): array
    {
        $this->freezeTime();
        $group = Group::factory()->create(['currency' => $currency, 'current_members' => $membershipStatus === 'left' ? 1 : 3]);
        GroupMember::factory()->for($group)->for($group->owner, 'user')->owner()->create();
        $members = [];
        foreach (['canceled', 'incomplete_expired', 'cancellation_pending', null] as $index => $status) {
            $members[] = GroupMember::factory()->for($group)->withStripeSubscription('sub_offline_reconcile_'.$index)
                ->create([
                    'status' => $index < 2 ? 'left' : $membershipStatus,
                    'subscription_status' => $status,
                    'cancellation_requested_at' => now()->subHour(),
                ])->fresh();
        }

        return [$group->fresh(), $members];
    }

    /** @return array{cancellations: int, subscriptions: int, refunds: int, checks: int, errors: int} */
    private function counts(int $cancellations, int $errors = 0): array
    {
        return ['cancellations' => $cancellations, 'subscriptions' => 0, 'refunds' => 0, 'checks' => 0, 'errors' => $errors];
    }
}
