<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le finding critique P0 de l'audit (« accès anonyme aux
 * identifiants ») : GET /api/groups et GET /api/groups/{group} sont
 * publiques (pas de middleware auth), et servaient auparavant le modèle
 * Group brut — donc credential_email/credential_password/credential_notes
 * (déchiffrés automatiquement par le cast 'encrypted') et les colonnes
 * Stripe (invite_token, stripe_*) étaient visibles par n'importe qui, sans
 * authentification. GroupController::index()/show() passent maintenant par
 * GroupResource, qui liste explicitement les champs exposés.
 */
class GroupApiLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_index_does_not_expose_credentials_or_invite_token(): void
    {
        Group::factory()->withCredentials()->create([
            'visibility' => 'invite_only',
            'invite_token' => 'super-secret-invite-token',
        ]);

        $response = $this->getJson('/api/groups');

        $response->assertOk();

        $json = $response->json('data') ?? $response->json();

        $raw = json_encode($json);

        $this->assertStringNotContainsString('compte-partage@example.com', $raw);
        $this->assertStringNotContainsString('super-secret-password', $raw);
        $this->assertStringNotContainsString('Profil 3', $raw);
        $this->assertStringNotContainsString('super-secret-invite-token', $raw);
    }

    public function test_group_show_does_not_expose_credentials_to_an_anonymous_visitor(): void
    {
        $group = Group::factory()->withCredentials()->create();

        $response = $this->getJson("/api/groups/{$group->id}");

        $response->assertOk();

        $raw = json_encode($response->json());

        $this->assertStringNotContainsString('compte-partage@example.com', $raw);
        $this->assertStringNotContainsString('super-secret-password', $raw);
        $this->assertStringNotContainsString('Profil 3', $raw);

        // Le prix, le nom, le statut restent légitimement publics.
        $data = $response->json('data') ?? $response->json();
        $this->assertSame($group->name, $data['name']);
    }

    public function test_group_show_does_not_expose_owner_stripe_or_trust_internals(): void
    {
        $owner = User::factory()->create([
            'stripe_connect_account_id' => 'acct_supersecret',
            'stripe_customer_id' => 'cus_supersecret',
        ]);
        $group = Group::factory()->for($owner, 'owner')->create();

        $response = $this->getJson("/api/groups/{$group->id}");

        $response->assertOk();

        $raw = json_encode($response->json());

        $this->assertStringNotContainsString('acct_supersecret', $raw);
        $this->assertStringNotContainsString('cus_supersecret', $raw);
        $this->assertStringNotContainsString($owner->email, $raw);
    }

    public function test_group_credentials_endpoint_still_requires_active_membership(): void
    {
        $group = Group::factory()->withCredentials()->create();
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/groups/{$group->id}/credentials");

        $response->assertStatus(403);
    }

    public function test_group_credentials_endpoint_requires_authentication(): void
    {
        $group = Group::factory()->withCredentials()->create();

        $response = $this->getJson("/api/groups/{$group->id}/credentials");

        $response->assertStatus(401);
    }
}
