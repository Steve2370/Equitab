<?php

namespace Tests\Support;

use App\Features\Subscription\Services\InvitationServiceCatalogue;
use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait InvitationCatalogueSelectionChecks
{
    public function test_normal_migrations_install_exactly_the_two_selected_active_offers(): void
    {
        $this->assertSame(['dropbox-family', 'nordpass-family'], Subscription::orderBy('slug')->pluck('slug')->all());
        foreach (Subscription::all() as $offer) {
            $this->assertTrue($offer->is_active);
            $this->assertFalse($offer->is_verified);
            $this->assertSame($offer->slug === 'nordpass-family' ? 749 : 2649, $offer->monthly_price);
            $this->assertSame($offer->slug === 'nordpass-family' ? 'yearly' : 'monthly', $offer->billing_cycle);
            $this->assertSame('invitation', $offer->access_mode);
            $this->assertSame('CAD', $offer->currency);
            $this->assertSame(6, $offer->max_members);
        }
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_release_reactivates_selected_offers_preserves_prices_and_is_idempotent(): void
    {
        Subscription::query()->update(['is_active' => false, 'monthly_price' => 1234]);
        $before = Subscription::orderBy('id')->get()->map->getAttributes()->all();
        $migration = require database_path('migrations/2026_10_08_000300_activate_selected_invitation_services.php');
        $migration->up();
        foreach (Subscription::orderBy('id')->get() as $index => $offer) {
            $expected = $before[$index];
            $actual = $offer->getAttributes();
            unset($expected['is_active'], $expected['updated_at'], $actual['is_active'], $actual['updated_at']);
            $this->assertSame($expected, $actual);
            $this->assertTrue($offer->is_active);
        }
        $snapshot = $this->selectionSnapshot();
        $this->travel(2)->hours();
        $migration->up();
        $this->assertSame($snapshot, $this->selectionSnapshot());
    }

    public function test_unreferenced_bitwarden_is_deleted_without_touching_other_services(): void
    {
        $bitwarden = $this->legacyBitwarden();
        $other = Subscription::factory()->create(['is_active' => false, 'monthly_price' => 4567]);
        $before = $other->fresh()->getAttributes();
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
        $this->assertDatabaseMissing('subscriptions', ['id' => $bitwarden->id]);
        $this->assertSame($before, $other->fresh()->getAttributes());
    }

    public function test_retirement_preserves_archived_groups_members_payments_and_drafts(): void
    {
        $bitwarden = $this->legacyBitwarden();
        $group = Group::factory()->for($bitwarden, 'subscription')->create();
        $member = GroupMember::factory()->for($group)->create();
        Payment::factory()->for($group)->for($member->user)->create();
        $draft = GroupDraft::create(['owner_id' => $group->owner_id, 'data' => ['subscription_id' => $bitwarden->id], 'version' => 1, 'status' => 'draft']);
        $group->delete();
        $draft->delete();
        $before = $this->selectionSnapshot();
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
        $after = $this->selectionSnapshot();
        unset($before['subscriptions'], $after['subscriptions']);
        $this->assertSame($before, $after);
        $this->assertFalse($bitwarden->fresh()->is_active);
        $this->assertSame('Bitwarden Families', $group->fresh()->subscription->name);
        $snapshot = $this->selectionSnapshot();
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
        $this->assertSame($snapshot, $this->selectionSnapshot());
    }

    public function test_draft_only_reference_also_preserves_the_retired_record(): void
    {
        $bitwarden = $this->legacyBitwarden();
        $draft = GroupDraft::create(['owner_id' => User::factory()->create()->id,
            'data' => ['subscription_id' => (string) $bitwarden->id], 'version' => 1, 'status' => 'draft']);
        $draft->delete();
        $before = $draft->fresh()->getAttributes();
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
        $this->assertFalse($bitwarden->fresh()->is_active);
        $this->assertSame($before, $draft->fresh()->getAttributes());
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_dry_run_does_not_activate_or_delete_existing_records(): void
    {
        Subscription::query()->update(['is_active' => false]);
        $this->legacyBitwarden();
        $snapshot = $this->selectionSnapshot();
        $rows = app(InvitationServiceCatalogue::class)->prepare();
        $this->assertCount(3, $rows);
        $this->assertSame('à activer', $rows[0]['action']);
        $this->assertSame('simulation : supprimé (sans référence)', $rows[2]['action']);
        $this->assertSame($snapshot, $this->selectionSnapshot());
    }

    public function test_retirement_identity_collision_rolls_back_activations(): void
    {
        Subscription::query()->update(['is_active' => false]);
        Subscription::factory()->create(['slug' => 'bitwarden-families', 'name' => 'Different historical offer']);
        $snapshot = $this->selectionSnapshot();
        try {
            app(InvitationServiceCatalogue::class)->prepare(apply: true);
            $this->fail('Ambiguous identity must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Collision de catalogue', $exception->getMessage());
        }
        $this->assertSame($snapshot, $this->selectionSnapshot());
    }

    public function test_release_rollback_refuses_to_guess_the_previous_catalogue(): void
    {
        $before = $this->selectionSnapshot();
        $migration = require database_path('migrations/2026_10_08_000300_activate_selected_invitation_services.php');
        try {
            $migration->down();
            $this->fail('No destructive automatic rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Retour arrière catalogue refusé', $exception->getMessage());
        }
        $this->assertSame($before, $this->selectionSnapshot());
    }

    private function legacyBitwarden(): Subscription
    {
        return Subscription::factory()->create(['name' => 'Bitwarden Families', 'slug' => 'bitwarden-families',
            'is_active' => true, 'access_mode' => 'invitation', 'monthly_price' => 1234]);
    }

    private function selectionSnapshot(): array
    {
        $result = [];
        foreach (['subscriptions', 'subscription_categories', 'groups', 'group_drafts', 'group_members', 'payments'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
