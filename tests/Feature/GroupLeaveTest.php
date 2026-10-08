<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingTestCase;

/**
 * Couvre le finding « élevé » de l'audit (« départ d'un membre ») :
 * GroupService::leave() ne résiliait jamais l'abonnement Stripe du membre
 * qui quittait — il continuait donc à être facturé pour un groupe qu'il
 * avait déjà quitté.
 */
class GroupLeaveTest extends BillingTestCase
{
    public function test_leaving_cancels_the_stripe_subscription_and_decrements_the_group(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $user = User::factory()->create();
        GroupMember::factory()
            ->for($group)
            ->for($user)
            ->withStripeSubscription('sub_leaving_member')
            ->create(['status' => 'active']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) use ($group, $user) {
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_leaving_member')->andReturnUsing(function () use ($group, $user): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $user->id, 'status' => 'left', 'subscription_status' => 'cancellation_pending']);
            });
        });

        app(GroupService::class)->leave($user, $group);

        $member = GroupMember::where('group_id', $group->id)->where('user_id', $user->id)->first();
        $this->assertSame('left', $member->status);
        $this->assertSame('canceled', $member->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_leaving_without_an_active_stripe_subscription_does_not_call_the_gateway(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $user = User::factory()->create();
        GroupMember::factory()
            ->for($group)
            ->for($user)
            ->create(['status' => 'pending_payment', 'stripe_subscription_id' => null]);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldNotReceive('cancelSubscription');
        });

        app(GroupService::class)->leave($user, $group);

        $member = GroupMember::where('group_id', $group->id)->where('user_id', $user->id)->first();
        $this->assertSame('left', $member->status);

        // A pending membership reserved a seat at join; release it once.
        $this->assertSame(1, $group->fresh()->current_members);
        app(GroupService::class)->leave($user, $group);
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_leaving_still_marks_the_member_left_when_stripe_cancellation_fails(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $user = User::factory()->create();
        GroupMember::factory()
            ->for($group)
            ->for($user)
            ->withStripeSubscription('sub_already_canceled')
            ->create(['status' => 'active']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')
                ->once()
                ->andThrow(new \Exception('No such subscription'));
        });

        app(GroupService::class)->leave($user, $group);

        $member = GroupMember::where('group_id', $group->id)->where('user_id', $user->id)->first();
        $this->assertSame('left', $member->status);
        $this->assertSame('cancellation_pending', $member->subscription_status);
        $this->assertSame('sub_already_canceled', $member->stripe_subscription_id);
        $this->assertNotNull($member->cancellation_requested_at);
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_a_full_group_reopens_when_a_member_leaves(): void
    {
        $group = Group::factory()->full()->create(['max_members' => 2, 'current_members' => 2]);
        $user = User::factory()->create();
        GroupMember::factory()->for($group)->for($user)->create(['status' => 'active']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->zeroOrMoreTimes();
        });

        app(GroupService::class)->leave($user, $group);

        $this->assertSame('open', $group->fresh()->status);
    }

    public function test_repeated_departure_retries_a_failed_cancellation_without_freeing_another_seat(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $user = User::factory()->create();
        $member = GroupMember::factory()->for($group)->for($user)->withStripeSubscription('sub_retry_departure')->create();
        $this->mock(PaymentGatewayInterface::class, function ($mock): void {
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_retry_departure')
                ->andThrow(new \RuntimeException('Synthetic timeout'))->ordered();
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_retry_departure')->andReturnNull()->ordered();
        });
        app(GroupService::class)->leave($user, $group);
        $requestedAt = $member->fresh()->cancellation_requested_at;
        $this->assertSame('cancellation_pending', $member->fresh()->subscription_status);
        app(GroupService::class)->leave($user, $group);
        app(GroupService::class)->leave($user, $group);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->assertTrue($requestedAt->equalTo($member->fresh()->cancellation_requested_at));
        $this->assertSame('sub_retry_departure', $member->fresh()->stripe_subscription_id);
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_owner_cannot_leave_using_a_member_departure(): void
    {
        $group = Group::factory()->create();
        GroupMember::factory()->for($group)->for($group->owner, 'user')->owner()->create();
        $this->mock(PaymentGatewayInterface::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));
        $this->actingAs($group->owner, 'sanctum')->postJson('/api/groups/'.$group->id.'/leave')->assertForbidden();
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame('active', $group->members()->firstOrFail()->status);
    }

    public function test_leaving_an_expired_incomplete_subscription_preserves_its_terminal_status(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $user = User::factory()->create();
        $member = GroupMember::factory()->for($group)->for($user)
            ->withStripeSubscription('sub_expired_departure')
            ->create(['status' => 'pending_payment', 'subscription_status' => 'incomplete_expired']);
        $this->mock(PaymentGatewayInterface::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));

        app(GroupService::class)->leave($user, $group);
        app(GroupService::class)->leave($user, $group);

        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('incomplete_expired', $member->fresh()->subscription_status);
        $this->assertNotNull($member->fresh()->cancellation_requested_at);
        $this->assertSame(1, $group->fresh()->current_members);
    }
}
