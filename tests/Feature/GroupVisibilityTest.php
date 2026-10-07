<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupAccess;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingTestCase;

class GroupVisibilityTest extends BillingTestCase
{
    public static function restrictedModes(): array
    {
        return ['private' => ['private'], 'legacy invitation' => ['invite_only']];
    }

    public function test_only_public_groups_appear_on_home_catalogue_and_api(): void
    {
        $public = Group::factory()->create();
        foreach (['private', 'invite_only'] as $mode) {
            $group = Group::factory()->create(['subscription_id' => $public->subscription_id, 'invite_token' => 'synthetic-'.$mode]);
            // Exercise genuine legacy rows, bypassing the new write normalization.
            DB::table('groups')->where('id', $group->id)->update(['visibility' => $mode]);
            $this->getJson('/api/groups/'.$group->id)->assertNotFound();
        }
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('openGroups', 1)->where('openGroups.0.id', $public->id));
        $this->get('/groups/service/'.$public->subscription->slug)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('groups', 1)->where('groups.0.id', $public->id));
        $this->getJson('/api/groups')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $public->id);
    }

    #[DataProvider('restrictedModes')]
    public function test_visibility_updates_supply_stable_links_and_public_transition_revokes_them(string $mode): void
    {
        $group = Group::factory()->create(['visibility' => 'public']);
        $owner = $group->owner;
        $url = '/api/groups/'.$group->id;
        $this->actingAs($owner, 'sanctum')->putJson($url, ['visibility' => $mode])->assertOk();
        $token = $group->fresh()->invite_token;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertDatabaseHas('groups', ['id' => $group->id, 'visibility' => 'private']);
        $this->get('/invite/'.$token)->assertOk();
        $this->putJson($url, ['visibility' => 'private', 'name' => 'Updated synthetic group'])->assertOk();
        $this->assertSame($token, $group->fresh()->invite_token);
        $this->putJson($url, ['visibility' => 'public'])->assertOk();
        $this->assertNull($group->fresh()->invite_token);
        $this->get('/invite/'.$token)->assertNotFound();
        $this->putJson($url, ['visibility' => 'private'])->assertOk();
        $this->assertNotSame($token, $group->fresh()->invite_token);
        $this->assertFalse(app(GroupAccess::class)->hasValidInvitation($group->fresh(), $token));
        $this->get('/invite/'.$token)->assertNotFound();
        $this->assertDatabaseCount('group_members', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    #[DataProvider('restrictedModes')]
    public function test_existing_restricted_links_are_preserved_and_missing_links_can_be_repaired(string $mode): void
    {
        $group = Group::factory()->create(['invite_token' => 'legacy-valid-synthetic']);
        DB::table('groups')->where('id', $group->id)->update(['visibility' => $mode]);
        $this->assertSame('private', $group->fresh()->visibility);
        $this->get('/invite/legacy-valid-synthetic')->assertOk();
        $this->actingAs($group->owner, 'sanctum')->putJson('/api/groups/'.$group->id, ['visibility' => 'private'])->assertOk();
        $this->assertSame('legacy-valid-synthetic', $group->fresh()->invite_token);
        $group->update(['invite_token' => null]);
        $this->putJson('/api/groups/'.$group->id, ['visibility' => 'private'])->assertOk();
        $this->assertNotEmpty($group->fresh()->invite_token);
    }

    public function test_outsiders_cannot_change_visibility_or_inject_their_own_invitation_token(): void
    {
        $group = Group::factory()->create();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/groups/'.$group->id, ['visibility' => 'private'])->assertForbidden();
        $this->assertNull($group->fresh()->invite_token);
        $this->actingAs($group->owner, 'sanctum')->putJson('/api/groups/'.$group->id, [
            'visibility' => 'private', 'invite_token' => 'attacker-controlled',
        ])->assertOk();
        $this->assertNotSame('attacker-controlled', $group->fresh()->invite_token);
        $token = $group->fresh()->invite_token;
        $this->putJson('/api/groups/'.$group->id, ['visibility' => 'everyone'])->assertUnprocessable();
        $this->assertSame($token, $group->fresh()->invite_token);
    }

    public function test_a_concurrent_payment_or_update_cannot_rotate_the_invitation_link(): void
    {
        $group = Group::factory()->private()->create(['invite_token' => 'synthetic-preserved-token']);
        $lock = Cache::store('database')->lock('billing:group:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($group->owner, 'sanctum')->putJson('/api/groups/'.$group->id, ['visibility' => 'public'])->assertStatus(503);
            $this->assertSame('synthetic-preserved-token', $group->fresh()->invite_token);
            $this->assertSame('private', $group->fresh()->visibility);
        } finally {
            $lock->release();
        }
        $this->putJson('/api/groups/'.$group->id, ['visibility' => 'public'])->assertOk();
        $this->assertNull($group->fresh()->invite_token);
    }

    public function test_a_historical_public_group_cannot_reactivate_its_old_private_link(): void
    {
        $group = Group::factory()->create(['visibility' => 'public', 'invite_token' => 'obsolete-synthetic-link']);
        $this->actingAs($group->owner, 'sanctum')->putJson('/api/groups/'.$group->id, ['visibility' => 'private'])->assertOk();
        $this->assertNotEmpty($group->fresh()->invite_token);
        $this->assertNotSame('obsolete-synthetic-link', $group->fresh()->invite_token);
        $this->get('/invite/obsolete-synthetic-link')->assertNotFound();
    }
}
