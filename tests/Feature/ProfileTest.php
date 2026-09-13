<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/dashboard/profile');

        $response->assertOk();
    }

    public function test_guest_is_redirected_from_profile_page(): void
    {
        $response = $this->get('/dashboard/profile');

        $response->assertRedirect('/login');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/dashboard/profile', [
                'name' => 'Test User',
                'phone' => '5145551234',
                'city' => 'Montréal',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('5145551234', $user->phone);
        $this->assertSame('Montréal', $user->city);
    }

    public function test_profile_update_requires_a_name(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/dashboard/profile', [
                'name' => '',
            ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_profile_update_does_not_allow_mass_assignment_of_unlisted_fields(): void
    {
        // Vérifie que updateProfile() n'accepte que les champs listés dans
        // $request->only([...]) — un utilisateur ne doit pas pouvoir changer
        // son propre statut d'identité ou de compte Stripe Connect via ce
        // formulaire.
        //
        // Note: 'trust_score' n'est volontairement pas testé ici — le
        // modèle User définit un accesseur getTrustScoreAttribute() qui
        // recalcule toujours la valeur à la volée (calculateTrustScore())
        // et ignore la colonne stockée, donc ce champ n'est de toute façon
        // jamais lisible tel quel depuis la base.
        $user = User::factory()->create([
            'identity_status' => 'pending',
            'stripe_connect_status' => 'pending',
        ]);

        $this
            ->actingAs($user)
            ->patch('/dashboard/profile', [
                'name' => 'Test User',
                'identity_status' => 'verified',
                'stripe_connect_status' => 'active',
            ]);

        $user->refresh();

        $this->assertSame('pending', $user->identity_status);
        $this->assertSame('pending', $user->stripe_connect_status);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/dashboard/preferences/account', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();

        // User utilise SoftDeletes : delete() met deleted_at plutôt que de
        // supprimer la ligne. fresh() contourne intentionnellement le scope
        // SoftDeletingScope (voir Model::newQueryForRestoration) donc reste
        // non-null après une suppression douce — ce n'est pas le bon test
        // ici. On vérifie plutôt que le scope par défaut (utilisé partout
        // ailleurs dans l'app) exclut bien l'utilisateur, et que deleted_at
        // est posé.
        $this->assertNull(User::find($user->id));
        $this->assertNotNull($user->fresh()->deleted_at);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/dashboard/preferences/account', [
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh());
    }
}
