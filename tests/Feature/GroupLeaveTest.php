<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le finding « élevé » de l'audit (« départ d'un membre ») :
 * GroupService::leave() ne résiliait jamais l'abonnement Stripe du membre
 * qui quittait — il continuait donc à être facturé pour un groupe qu'il
 * avait déjà quitté.
 */
class GroupLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_cancels_the_stripe_subscription_and_decrements_the_group(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $user = User::factory()->create();
        GroupMember::factory()
            ->for($group)
            ->for($user)
            ->withStripeSubscription('sub_leaving_member')
            ->create(['status' => 'active']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_leaving_member');
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

        // Le membre n'était pas 'active' : current_members ne doit pas
        // descendre une deuxième fois.
        $this->assertSame(2, $group->fresh()->current_members);
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
}
