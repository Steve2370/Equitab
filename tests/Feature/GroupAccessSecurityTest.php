<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupAccess;
use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingUnavailable;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\BillingTestCase;

class GroupAccessSecurityTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
        $this->mock(PaymentGatewayInterface::class, function ($mock): void {
            foreach (get_class_methods(PaymentGatewayInterface::class) as $method) {
                $mock->shouldNotReceive($method);
            }
        });
    }

    public function test_private_details_are_hidden_from_anonymous_visitors_and_outsiders(): void
    {
        $group = Group::factory()->private()->withCredentials()->create(['invite_token' => 'private-synthetic-token']);
        $this->getJson('/api/groups/'.$group->id)->assertNotFound()
            ->assertDontSee($group->name)->assertDontSee($group->credential_password);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/groups/'.$group->id)->assertNotFound();
    }

    public static function privateViewers(): array
    {
        return ['owner' => ['owner'], 'active member' => ['active'], 'reserved member' => ['pending_payment']];
    }

    #[DataProvider('privateViewers')]
    public function test_private_details_remain_available_to_authorized_bearer_users(string $role): void
    {
        $group = Group::factory()->private()->withCredentials()->create(['invite_token' => 'private-synthetic-token']);
        $user = $role === 'owner' ? $group->owner : User::factory()->create();
        if ($role !== 'owner') {
            GroupMember::factory()->for($group)->for($user)->create(['status' => $role]);
        }
        $token = $user->createToken('isolated-group-read')->plainTextToken;
        $this->withToken($token)->getJson('/api/groups/'.$group->id)->assertOk()
            ->assertJsonPath('data.id', $group->id)
            ->assertDontSee($group->credential_password)->assertDontSee($group->invite_token);
    }

    public function test_invite_page_passes_only_its_validated_token_and_no_credentials(): void
    {
        $group = Group::factory()->private()->withCredentials()->create(['invite_token' => 'invitation-fixture']);
        $this->get('/invite/invitation-fixture')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('InvitePage')->where('inviteToken', 'invitation-fixture')
            ->where('group.id', $group->id)->missing('group.credential_password'));
        $this->get('/invite/incorrect-fixture')->assertNotFound();
        $group->update(['status' => 'closed']);
        $this->get('/invite/invitation-fixture')->assertNotFound();
    }

    public static function invitationStates(): array
    {
        return [
            'private open' => ['private', 'open', 'invite-fixture', true],
            'invitation full' => ['invite_only', 'full', 'invite-fixture', true],
            'closed' => ['private', 'closed', 'invite-fixture', false],
            'suspended' => ['private', 'suspended', 'invite-fixture', false],
            'public' => ['public', 'open', 'invite-fixture', false],
            'incorrect token' => ['private', 'open', 'wrong-fixture', false],
            'missing token' => ['private', 'open', null, false],
            'empty token' => ['private', 'open', '', false],
        ];
    }

    #[DataProvider('invitationStates')]
    public function test_invitation_authorization_uses_visibility_state_and_exact_token(string $visibility, string $status, ?string $token, bool $allowed): void
    {
        $group = Group::factory()->create(['visibility' => $visibility, 'status' => $status, 'invite_token' => 'invite-fixture']);
        $this->assertSame($allowed, app(GroupAccess::class)->hasValidInvitation($group, $token));
        $group->delete();
        $this->assertFalse(app(GroupAccess::class)->hasValidInvitation($group, $token));
    }

    public function test_private_proration_requires_an_invitation_and_exposes_no_credentials(): void
    {
        $group = Group::factory()->private()->withCredentials()->create(['invite_token' => 'proration-fixture']);
        $url = '/api/groups/'.$group->id.'/proration';
        $this->getJson($url)->assertNotFound();
        $this->getJson($url.'?invite_token=wrong-fixture')->assertNotFound();
        $this->getJson($url.'?invite_token=proration-fixture')->assertOk()
            ->assertJsonStructure(['amount_today', 'amount_recurring', 'next_billing_date'])
            ->assertDontSee($group->credential_password)->assertDontSee($group->invite_token);
        $this->getJson($url.'?invite_token[]=proration-fixture')->assertUnprocessable();
        $group->update(['status' => 'closed']);
        $this->getJson($url.'?invite_token=proration-fixture')->assertNotFound();
    }

    public function test_catalogue_and_invitation_hide_groups_whose_owner_has_been_deleted(): void
    {
        $group = Group::factory()->create(['name' => 'Synthetic orphaned group']);
        $private = Group::factory()->private()->for($group->owner, 'owner')->create(['invite_token' => 'orphaned-invite']);
        $group->owner->delete();
        $this->get('/invite/'.$private->invite_token)->assertNotFound();
        $this->get('/groups/service/'.$group->subscription->slug)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ServiceGroups')->has('groups', 0));
    }

    public static function deniedAccessStates(): array
    {
        return [
            'expired' => ['expired'], 'expires now' => ['expires-now'],
            'cancellation requested' => ['cancel-requested'], 'left' => ['left'],
            'kicked' => ['kicked'], 'pending' => ['pending_payment'],
            'membership suspended' => ['suspended'], 'group closed' => ['group-closed'],
            'group suspended' => ['group-suspended'], 'account suspended' => ['account-suspended'],
            'account banned' => ['account-banned'], 'email unverified' => ['unverified'],
            'subscription canceled' => ['stripe-canceled'], 'period unknown' => ['stripe-unknown-period'],
        ];
    }

    #[DataProvider('deniedAccessStates')]
    public function test_credentials_messages_and_channels_reject_ineligible_members(string $state): void
    {
        [$group, $user, $member] = $this->activeMembership();
        match ($state) {
            'expired' => $member->update(['current_period_end' => now()->subMinute()]),
            'expires-now' => $member->update(['current_period_end' => now()]),
            'cancel-requested' => $member->update(['cancellation_requested_at' => now()]),
            'group-closed' => $group->update(['status' => 'closed']),
            'group-suspended' => $group->update(['status' => 'suspended']),
            'account-suspended' => $user->update(['status' => 'suspended', 'suspended_until' => now()->addDay()]),
            'account-banned' => $user->update(['status' => 'banned']),
            'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
            'stripe-canceled' => $member->update(['subscription_status' => 'canceled']),
            'stripe-unknown-period' => $member->update(['current_period_end' => null]),
            default => $member->update(['status' => $state]),
        };
        $message = Message::create([
            'group_id' => $group->id, 'sender_id' => $group->owner_id,
            'receiver_id' => $user->id, 'body' => 'Private message fixture',
        ]);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/groups/'.$group->id.'/credentials')->assertForbidden()->assertDontSee($group->credential_password);
        $this->getJson('/api/groups/'.$group->id.'/messages')->assertForbidden()->assertDontSee($message->body);
        $this->postJson('/api/groups/'.$group->id.'/messages', ['body' => 'Forbidden message'])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
        $this->assertNull($message->fresh()->read_at);
        $this->assertFalse($this->channelAllows($user, $group, $user->id, $group->owner_id));
        Mail::assertNothingSent();
    }

    public function test_active_member_has_credentials_and_owner_chat_but_cannot_choose_another_recipient(): void
    {
        [$group, $user] = $this->activeMembership();
        $other = User::factory()->create();
        GroupMember::factory()->for($group)->for($other)->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/groups/'.$group->id.'/credentials')
            ->assertOk()->assertJsonPath('password', $group->credential_password);
        $this->postJson('/api/groups/'.$group->id.'/messages', ['body' => 'Synthetic message', 'receiver_id' => $other->id])
            ->assertCreated();
        $this->assertDatabaseHas('messages', ['sender_id' => $user->id, 'receiver_id' => $group->owner_id]);
        $this->assertDatabaseMissing('messages', ['receiver_id' => $other->id]);
        $this->assertTrue($this->channelAllows($user, $group, $user->id, $group->owner_id));
        $this->assertFalse($this->channelAllows($user, $group, $user->id, $other->id));
        $this->assertFalse($this->channelAllows($user, $group, $other->id, $group->owner_id));
    }

    public function test_legacy_non_stripe_members_remain_supported_but_a_known_expiry_is_respected(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create();
        $member = GroupMember::factory()->for($group)->for($user)->create();
        $this->assertTrue(app(GroupAccess::class)->canUseService($user, $group));
        $member->update(['current_period_end' => now()->subSecond()]);
        $this->assertFalse(app(GroupAccess::class)->canUseService($user, $group));
    }

    public function test_owner_cannot_read_send_or_subscribe_to_a_conversation_with_an_outsider_or_expired_member(): void
    {
        [$group, $user, $member] = $this->activeMembership();
        $member->update(['current_period_end' => now()->subMinute()]);
        $outsider = User::factory()->create();
        $this->actingAs($group->owner, 'sanctum');
        foreach ([$outsider, $user, $group->owner] as $other) {
            $this->getJson('/api/groups/'.$group->id.'/messages?other_id='.$other->id)->assertForbidden();
            $this->postJson('/api/groups/'.$group->id.'/messages', ['body' => 'Blocked', 'receiver_id' => $other->id])->assertUnprocessable();
            $this->assertFalse($this->channelAllows($group->owner, $group, $group->owner_id, $other->id));
        }
        $this->getJson('/api/groups/'.$group->id.'/chat-members')->assertOk()->assertExactJson([]);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_chat_index_excludes_expired_and_soft_deleted_groups(): void
    {
        [$group, $user, $member] = $this->activeMembership();
        $member->update(['current_period_end' => now()->subMinute()]);
        $deleted = Group::factory()->create();
        GroupMember::factory()->for($deleted)->for($user)->create();
        $deleted->delete();
        $this->actingAs($user)->get('/dashboard/chat')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Chat')->has('conversations', 0));
    }

    public function test_join_rereads_stale_group_capacity_and_cannot_reserve_the_last_seat_twice(): void
    {
        $group = Group::factory()->create(['current_members' => 1, 'max_members' => 2]);
        app(GroupService::class)->join(User::factory()->create(), $group);
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertSame('full', $group->fresh()->status);
        $this->expectHttpFailure(409, fn () => app(GroupService::class)->join(User::factory()->create(), $group));
        $this->assertSame(2, $group->fresh()->current_members);
        $this->assertDatabaseCount('group_members', 1);
    }

    public function test_join_rereads_a_suspension_and_closure_after_the_models_were_loaded(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create();
        User::whereKey($user->id)->update(['status' => 'suspended', 'suspended_until' => now()->addDay()]);
        $this->expectHttpFailure(403, fn () => app(GroupService::class)->join($user, $group));
        Group::whereKey($group->id)->update(['status' => 'closed']);
        $this->expectHttpFailure(403, fn () => app(GroupService::class)->join(User::factory()->create(), $group));
        $this->assertDatabaseCount('group_members', 0);
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_join_shares_the_billing_lock_and_does_not_mutate_while_checkout_owns_it(): void
    {
        $group = Group::factory()->create();
        $lock = Cache::store('database')->lock('billing:group:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            try {
                app(GroupService::class)->join(User::factory()->create(), $group);
                $this->fail('An in-flight checkout must block the competing reservation.');
            } catch (BillingUnavailable) {
                $this->assertDatabaseCount('group_members', 0);
                $this->assertSame(1, $group->fresh()->current_members);
            }
        } finally {
            $lock->release();
        }
    }

    public function test_close_commits_every_cancellation_before_network_and_continues_after_a_timeout(): void
    {
        $group = Group::factory()->create(['current_members' => 4]);
        $members = collect(['active', 'pending_payment', 'suspended'])->map(fn ($status, $i) => GroupMember::factory()->for($group)->withStripeSubscription('sub_group_close_'.$i)->create(['status' => $status]));
        $snapshots = [];
        $this->mock(PaymentGatewayInterface::class, function ($mock) use ($group, &$snapshots): void {
            $mock->shouldReceive('cancelSubscription')->times(3)->andReturnUsing(function (string $id) use ($group, &$snapshots): void {
                $snapshots[] = [
                    'transaction' => DB::transactionLevel(),
                    'status' => $group->fresh()->status,
                    'left' => $group->members()->where('status', 'left')->count(),
                    'requested' => $group->members()->whereNotNull('cancellation_requested_at')->count(),
                ];
                if ($id === 'sub_group_close_0') {
                    throw new RuntimeException('Synthetic timeout');
                }
            });
        });
        $this->assertFalse(app(GroupService::class)->close($group->owner, $group));
        $this->assertCount(3, $snapshots);
        foreach ($snapshots as $snapshot) {
            $this->assertSame(['transaction' => 0, 'status' => 'closed', 'left' => 3, 'requested' => 3], $snapshot);
        }
        $this->assertSame(1, $group->fresh()->current_members);
        $this->assertSame('cancellation_pending', $members[0]->fresh()->subscription_status);
        $this->assertSame('sub_group_close_0', $members[0]->fresh()->stripe_subscription_id);
        $this->assertSame('canceled', $members[1]->fresh()->subscription_status);
        $this->assertSame('canceled', $members[2]->fresh()->subscription_status);
    }

    public function test_delete_is_soft_and_retains_the_pending_cancellation_for_retry(): void
    {
        [$group, $user, $member] = $this->activeMembership();
        $this->mock(PaymentGatewayInterface::class, fn ($mock) => $mock->shouldReceive('cancelSubscription')
            ->once()->with($member->stripe_subscription_id)->andThrow(new RuntimeException('Synthetic timeout')));
        $this->actingAs($group->owner, 'sanctum')->deleteJson('/api/groups/'.$group->id)->assertOk();
        $this->assertSoftDeleted($group);
        $this->assertSame('closed', Group::withTrashed()->findOrFail($group->id)->status);
        $this->assertDatabaseHas('group_members', [
            'id' => $member->id, 'status' => 'left', 'subscription_status' => 'cancellation_pending',
            'stripe_subscription_id' => 'sub_group_access',
        ]);
        $this->assertNotNull($member->fresh()->cancellation_requested_at);
        $this->actingAs($user, 'sanctum')->getJson('/api/groups/'.$group->id.'/credentials')->assertNotFound();
    }

    public function test_close_and_delete_are_owner_only_and_reject_before_any_mutation(): void
    {
        [$group, $user, $member] = $this->activeMembership();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/groups/'.$group->id)->assertForbidden();
        $this->actingAs($user, 'web')->patch('/groups/'.$group->id.'/close')->assertForbidden();
        $this->expectHttpFailure(403, fn () => app(GroupService::class)->close($user, $group));
        $this->assertSame('open', $group->fresh()->status);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertNull($member->fresh()->cancellation_requested_at);
    }

    public function test_status_update_cannot_bypass_cancellation_or_reopen_a_closed_group(): void
    {
        [$group, , $member] = $this->activeMembership();
        $this->mock(PaymentGatewayInterface::class, fn ($mock) => $mock->shouldReceive('cancelSubscription')
            ->once()->with($member->stripe_subscription_id));
        $this->actingAs($group->owner, 'sanctum')->putJson('/api/groups/'.$group->id, ['status' => 'closed'])->assertOk();
        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);
        $this->putJson('/api/groups/'.$group->id, ['status' => 'open'])->assertConflict();
        $this->assertSame('closed', $group->fresh()->status);
    }

    private function activeMembership(): array
    {
        $group = Group::factory()->withCredentials()->create(['current_members' => 2]);
        $user = User::factory()->create();
        $member = GroupMember::factory()->for($group)->for($user)->withStripeSubscription('sub_group_access')
            ->create(['current_period_end' => now()->addMonth()]);

        return [$group, $user, $member];
    }

    private function channelAllows(User $user, Group $group, int $first, int $second): bool
    {
        $callback = Broadcast::getChannels()->get('chat.{groupId}.{userId1}.{userId2}');
        $this->assertIsCallable($callback);

        return $callback($user, $group->id, $first, $second);
    }

    private function expectHttpFailure(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected an authorization or capacity failure.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
