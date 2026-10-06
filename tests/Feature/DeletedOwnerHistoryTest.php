<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BillingTestCase;

class DeletedOwnerHistoryTest extends BillingTestCase
{
    public function test_public_pagination_filters_deleted_owners_before_counting_or_limiting(): void
    {
        $deletedOwner = User::factory()->create(['name' => 'Synthetic deleted owner']);
        $subscription = Subscription::factory()->create();
        Group::factory()->count(16)->for($deletedOwner, 'owner')->for($subscription)->create();
        $visible = Group::factory()->for($subscription)->create();
        $deletedOwner->delete();

        $this->getJson('/api/groups')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertDontSee('Synthetic deleted owner');
        $this->assertDatabaseCount('groups', 17);
        $this->assertSoftDeleted($deletedOwner);
    }

    public function test_home_excludes_deleted_owners_before_the_ten_group_limit(): void
    {
        $deletedOwner = User::factory()->create(['name' => 'Synthetic removed identity']);
        $subscription = Subscription::factory()->create();
        Group::factory()->count(11)->for($deletedOwner, 'owner')->for($subscription)->create();
        $visible = Group::factory()->for($subscription)->create();
        $deletedOwner->delete();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')->has('openGroups', 1)
            ->where('openGroups.0.id', $visible->id)
            ->where('openGroups.0.ownerName', $visible->owner->display_name))
            ->assertDontSee('Synthetic removed identity');
        $this->assertDatabaseCount('groups', 12);
    }

    public function test_joined_groups_are_preserved_with_an_anonymous_deleted_owner_label(): void
    {
        [$group, $member, $user] = $this->historicalMembership();
        $live = Group::factory()->create();
        GroupMember::factory()->for($live)->for($user)->create(['share_amount' => 650]);

        $this->actingAs($user)->get('/dashboard/subscriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Subscriptions')
                ->has('joinedSubscriptions', 2)
                ->where('joinedSubscriptions.0.id', $group->id)
                ->where('joinedSubscriptions.0.ownerName', 'Utilisateur supprimé')
                ->where('joinedSubscriptions.0.pricePerMember', $member->share_amount)
                ->where('joinedSubscriptions.0.status', 'active')
                ->where('joinedSubscriptions.1.id', $live->id)
                ->where('joinedSubscriptions.1.ownerName', $live->owner->display_name))
            ->assertDontSee('Synthetic former owner')->assertDontSee('former-owner@example.test');
        $this->assertNull($group->fresh()->owner);
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_dashboard_keeps_existing_activity_and_pending_payment_when_only_the_owner_is_deleted(): void
    {
        [$group, , $user] = $this->historicalMembership();
        $pending = Payment::factory()->for($group)->for($user)->create([
            'status' => 'pending', 'amount' => 477, 'paid_at' => null,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')
                ->where('activeSubscriptionsCount', 1)->where('monthlySpend', 5)
                ->where('totalSavings', 19)
                ->has('upcomingPayments', 1)->where('upcomingPayments.0.id', $pending->id)
                ->where('upcomingPayments.0.groupName', 'Synthetic service — Historical group')
                ->where('upcomingPayments.0.amount', 477)->where('upcomingPayments.0.status', 'pending'));
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_payment_history_keeps_all_statuses_after_group_and_owner_soft_deletion(): void
    {
        [$group, , $user] = $this->historicalMembership();
        foreach (['completed', 'refunded', 'pending'] as $i => $status) {
            Payment::factory()->for($group)->for($user)->create([
                'status' => $status, 'amount' => 500 + $i,
                'paid_at' => now()->subDays($i),
            ]);
        }
        Payment::factory()->for($group)->create(['amount' => 99999]);
        $group->delete();

        $this->actingAs($user)->get('/dashboard/payments')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Payments')
                ->where('payments.total', 3)->has('payments.data', 3)
                ->where('payments.data.0.groupName', 'Synthetic service')
                ->where('payments.data.0.status', 'completed')->where('payments.data.0.amount', 500)
                ->where('payments.data.1.status', 'refunded')->where('payments.data.1.amount', 501)
                ->where('payments.data.2.status', 'pending')->where('payments.data.2.amount', 502))
            ->assertDontSee('fixture-private-service-password')->assertDontSee('former-owner@example.test');
        $this->assertSoftDeleted($group);
        $this->assertDatabaseCount('payments', 4);
    }

    public function test_archived_group_payment_remains_visible_without_reviving_global_relations_or_active_groups(): void
    {
        [$group, $member, $user] = $this->historicalMembership();
        $pending = Payment::factory()->for($group)->for($user)->create(['status' => 'pending', 'paid_at' => null]);
        $group->delete();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')
                ->where('activeSubscriptionsCount', 0)->has('upcomingPayments', 1)
                ->where('upcomingPayments.0.id', $pending->id)
                ->where('upcomingPayments.0.groupName', 'Synthetic service — Historical group'));
        $this->assertNull($member->fresh()->group);
        $this->assertNull($pending->fresh()->group);
        $this->assertNull(Group::find($group->id));
        $this->get('/dashboard/subscriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('joinedSubscriptions', 0));
    }

    public function test_historical_payments_remain_scoped_to_the_authenticated_user(): void
    {
        [$group, , $user] = $this->historicalMembership();
        Payment::factory()->for($group)->for($user)->create(['status' => 'pending']);
        $group->delete();
        $this->actingAs(User::factory()->create())->get('/dashboard/payments')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payments.total', 0)->has('payments.data', 0));
        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('upcomingPayments', 0));
    }

    private function historicalMembership(): array
    {
        $owner = User::factory()->create(['name' => 'Synthetic former owner', 'email' => 'former-owner@example.test']);
        $subscription = Subscription::factory()->create(['name' => 'Synthetic service', 'monthly_price' => 2400]);
        $group = Group::factory()->for($owner, 'owner')->for($subscription)->create([
            'name' => 'Historical group', 'current_members' => 2,
            'credential_password' => 'fixture-private-service-password',
        ]);
        $user = User::factory()->create();
        $member = GroupMember::factory()->for($group)->for($user)->create(['share_amount' => 500]);
        $owner->delete();

        return [$group, $member, $user];
    }
}
