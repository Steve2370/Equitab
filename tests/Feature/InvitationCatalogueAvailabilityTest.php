<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupAccess;
use App\Features\Group\Services\GroupService;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Subscription\Services\InvitationServiceCatalogue;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupDraftTestCase;

class InvitationCatalogueAvailabilityTest extends GroupDraftTestCase
{
    public function test_selected_services_appear_in_public_and_owner_catalogues_with_no_invented_price(): void
    {
        $expected = ['dropbox-family', 'nordpass-family'];
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalogServices', fn ($services) => collect($services)->pluck('slug')->sort()->values()->all() === $expected
                && collect($services)->every(fn ($service) => $service['pricePerMember'] === ($service['slug'] === 'nordpass-family' ? 7.49 : 26.49))));
        $this->get('/services')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => collect($categories)->flatMap(fn ($category) => $category['subscriptions'])
                ->pluck('slug')->sort()->values()->all() === $expected
                && collect($categories)->flatMap(fn ($category) => $category['subscriptions'])
                    ->every(fn ($service) => $service['monthly_price'] === ($service['slug'] === 'nordpass-family' ? 749 : 2649)
                        && $service['currency'] === 'CAD' && $service['max_members'] === 6)));
        $this->actingAs(User::factory()->create())->get('/dashboard/groups/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('subscriptions', 2)
                ->where('subscriptions.0.slug', 'dropbox-family')->where('subscriptions.1.slug', 'nordpass-family')
                ->where('subscriptions.0.monthly_price', 2649)->where('subscriptions.0.currency', 'CAD')
                ->where('subscriptions.1.monthly_price', 749)->where('subscriptions.1.currency', 'CAD'));
        $this->get('/groups/service/bitwarden-families')->assertNotFound();
        $this->assertNoDraftSideEffects();
    }

    public static function selectedOffers(): array
    {
        return [['dropbox-family'], ['nordpass-family']];
    }

    #[DataProvider('selectedOffers')]
    public function test_each_selected_service_can_publish_a_real_cost_invitation_group(string $slug): void
    {
        $offer = Subscription::where('slug', $slug)->sole();
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, $this->validDraftData($offer));
        $this->productGateway->shouldReceive('ensureProduct')->once()->andReturn('prod_synthetic_invitation');
        $this->actingAs($owner)->postJson('/group-drafts/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])
            ->assertSuccessful();
        $group = Group::sole();
        $this->assertSame($offer->id, $group->subscription_id);
        $this->assertSame('invitation', $group->access_mode);
        $this->assertSame(1999, $group->total_price);
        $this->assertSame('CAD', $group->currency);
        $this->assertSame($slug === 'nordpass-family' ? 749 : 2649, $offer->fresh()->monthly_price);
        $this->assertDatabaseCount('payments', 0);
        $this->get('/groups/service/'.$slug)->assertOk()->assertInertia(fn (Assert $page) => $page->has('groups', 1));
    }

    public function test_retired_offer_blocks_new_sales_and_public_discovery_but_keeps_member_access(): void
    {
        $offer = Subscription::factory()->create(['name' => 'Bitwarden Families', 'slug' => 'bitwarden-families', 'access_mode' => 'invitation']);
        $group = Group::factory()->for($offer, 'subscription')->create(['visibility' => 'public', 'status' => 'open']);
        $member = GroupMember::factory()->for($group)->create(['status' => 'active']);
        app(InvitationServiceCatalogue::class)->prepare(apply: true);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('openGroups', 0)
            ->where('catalogServices', fn ($services) => ! collect($services)->pluck('slug')->contains('bitwarden-families')));
        $this->get('/groups/service/bitwarden-families')->assertNotFound();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson('/api/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/groups/'.$group->id)->assertNotFound();
        $this->postJson('/api/groups/'.$group->id.'/join')->assertForbidden();
        $draft = $this->draftFor($outsider, $this->validDraftData($offer));
        $this->postJson('/group-drafts/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])->assertUnprocessable();
        $this->assertTrue(app(GroupAccess::class)->canUseService($member->user, $group->fresh()));
        $this->actingAs($member->user)->getJson('/api/groups/'.$group->id)->assertOk();
        $this->getJson('/api/groups/'.$group->id.'/service-access')->assertOk()->assertJsonPath('status', 'awaiting_owner');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('subscription_attempts', 0);
    }

    public function test_existing_payment_commitments_remain_resumable_after_retirement(): void
    {
        $offer = Subscription::factory()->create(['is_active' => false]);
        $group = Group::factory()->for($offer, 'subscription')->create(['status' => 'open', 'visibility' => 'private']);
        $member = GroupMember::factory()->for($group)->create(['status' => 'pending_payment', 'stripe_subscription_id' => 'sub_synthetic_existing']);
        $access = app(GroupAccess::class);
        $access->authorizeSubscription($member->user, $group, null, $member);
        $member->update(['stripe_subscription_id' => null]);
        DB::table('subscription_attempts')->insert(['id' => (string) Str::uuid(), 'group_id' => $group->id,
            'user_id' => $member->user_id, 'parameters' => 'synthetic-no-secrets', 'started_at' => now()]);
        $access->authorizeSubscription($member->user, $group, null, $member->fresh());
        $this->assertDatabaseCount('subscription_attempts', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_legacy_group_creation_rechecks_catalogue_after_remote_owner_verification(): void
    {
        $offer = Subscription::where('slug', 'dropbox-family')->sole();
        $owner = $this->readyOwner();
        $this->ownerStripe->shouldReceive('retrieveAccount')->once()->with('acct_draft_test')
            ->andReturnUsing(function () use ($offer) {
                $offer->update(['is_active' => false]);

                return new OwnerConnectState('acct_draft_test', true, true, true);
            });
        try {
            app(GroupService::class)->create($owner, $this->validDraftData($offer));
            $this->fail('Retired offer must not create a group.');
        } catch (ModelNotFoundException) {
            $this->assertNoDraftSideEffects();
        }
    }
}
