<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupService;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Models\Group;
use App\Models\Subscription;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le finding critique P0 « autorisation manquante à la
 * création/adhésion d'un groupe » : GroupPolicy existait mais n'était
 * jamais invoquée, et GroupService::join() ne contrôlait ni la visibilité,
 * ni le statut du compte. Ces règles sont désormais appliquées directement
 * dans GroupService, seul point d'entrée pour créer/rejoindre un groupe
 * (web ET API partagent ce même service).
 */
class GroupAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function mockGateway(): void
    {
        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createProduct')->andReturn(['product_id' => 'prod_fake']);
        });
    }

    private function validGroupData(Subscription $subscription): array
    {
        return [
            'subscription_id' => $subscription->id,
            'name' => 'Groupe Test',
            'description' => null,
            'tier' => 'standard',
            'max_members' => 4,
            'total_price' => 2000,
            'split_type' => 'equal',
            'visibility' => 'public',
            'renewal_date' => now()->addMonth()->toDateString(),
            'auto_renew' => true,
        ];
    }

    public function test_group_creation_is_rejected_when_identity_is_not_verified(): void
    {
        $this->mockGateway();

        $owner = User::factory()->create([
            'identity_status' => 'unverified',
            'stripe_connect_status' => 'active',
        ]);
        $subscription = Subscription::factory()->create();

        $this->expectException(Exception::class);

        app(GroupService::class)->create($owner, $this->validGroupData($subscription));
    }

    public function test_group_creation_is_rejected_when_stripe_connect_is_not_active(): void
    {
        $this->mockGateway();

        $owner = User::factory()->create([
            'identity_status' => 'verified',
            'stripe_connect_status' => 'pending',
        ]);
        $subscription = Subscription::factory()->create();

        $this->expectException(Exception::class);

        app(GroupService::class)->create($owner, $this->validGroupData($subscription));
    }

    public function test_group_creation_succeeds_when_owner_is_fully_verified(): void
    {
        $this->mockGateway();

        $owner = User::factory()->create([
            'identity_status' => 'verified',
            'stripe_connect_status' => 'active',
        ]);
        $subscription = Subscription::factory()->create();

        $group = app(GroupService::class)->create($owner, $this->validGroupData($subscription));

        $this->assertDatabaseHas('groups', ['id' => $group->id, 'owner_id' => $owner->id]);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_creation_bypass_attempt_via_the_api_is_also_rejected(): void
    {
        $this->mockGateway();

        $owner = User::factory()->create([
            'identity_status' => 'unverified',
            'stripe_connect_status' => 'not_started',
        ]);
        $subscription = Subscription::factory()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/groups', $this->validGroupData($subscription));

        // Le contrôleur transforme l'Exception du service en redirection
        // 'back' en HTTP web, mais en JSON via la route API ; dans tous les
        // cas le groupe ne doit pas exister.
        $this->assertSame(0, Group::count());
        $this->assertNotSame(201, $response->status());
    }

    public function test_join_is_rejected_for_a_suspended_account(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create([
            'status' => 'suspended',
            'suspended_until' => now()->addDay(),
        ]);

        $this->expectException(Exception::class);

        app(GroupService::class)->join($user, $group);
    }

    public function test_join_is_rejected_when_email_is_not_verified(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->unverified()->create();

        $this->expectException(Exception::class);

        app(GroupService::class)->join($user, $group);
    }

    public function test_join_is_rejected_for_a_private_group_without_an_invite_token(): void
    {
        $group = Group::factory()->private()->create(['invite_token' => 'the-real-token']);
        $user = User::factory()->create();

        $this->expectException(Exception::class);

        app(GroupService::class)->join($user, $group, null);
    }

    public function test_join_is_rejected_for_a_private_group_with_the_wrong_invite_token(): void
    {
        $group = Group::factory()->private()->create(['invite_token' => 'the-real-token']);
        $user = User::factory()->create();

        $this->expectException(Exception::class);

        app(GroupService::class)->join($user, $group, 'wrong-token');
    }

    public function test_join_succeeds_for_a_private_group_with_the_correct_invite_token(): void
    {
        $group = Group::factory()->private()->create(['invite_token' => 'the-real-token']);
        $user = User::factory()->create();

        app(GroupService::class)->join($user, $group, 'the-real-token');

        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id,
            'user_id' => $user->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_join_is_rejected_when_group_is_already_closed(): void
    {
        $group = Group::factory()->create(['status' => 'closed']);
        $user = User::factory()->create();

        $this->expectException(Exception::class);

        app(GroupService::class)->join($user, $group);
    }

    public function test_join_bypass_attempt_via_the_api_is_also_rejected_for_suspended_users(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create([
            'status' => 'suspended',
            'suspended_until' => now()->addDay(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/groups/{$group->id}/join");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);
    }
}
