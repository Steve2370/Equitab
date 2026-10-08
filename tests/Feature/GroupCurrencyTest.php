<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupDraftService;
use App\Features\Group\Services\PublishGroupDraft;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BillingCurrencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\GroupDraftTestCase;

class GroupCurrencyTest extends GroupDraftTestCase
{
    public function test_group_keeps_its_native_currency_when_the_catalogue_changes(): void
    {
        $subscription = Subscription::factory()->create(['currency' => 'CAD']);
        $group = Group::factory()->for($subscription)->create(['total_price' => 1701]);
        $subscription->update(['currency' => 'EUR', 'monthly_price' => 4500]);

        $this->assertSame('CAD', $group->fresh()->currency);
        $this->assertSame(1701, $group->fresh()->total_price);
        $this->getJson('/api/groups/'.$group->id)->assertOk()->assertJsonPath('data.currency', 'CAD');
    }

    public function test_a_published_group_currency_cannot_be_changed_through_the_model(): void
    {
        $group = Group::factory()->create(['currency' => 'EUR']);
        try {
            $group->update(['currency' => 'CAD']);
            $this->fail('A published native currency must be immutable.');
        } catch (LogicException) {
            $this->assertSame('EUR', $group->fresh()->currency);
        }
    }

    public function test_currency_cannot_be_changed_by_the_group_update_endpoint(): void
    {
        $owner = $this->readyOwner();
        $group = Group::factory()->for($owner, 'owner')->create();
        Sanctum::actingAs($owner);
        $this->putJson('/api/groups/'.$group->id, ['currency' => 'EUR'])->assertUnprocessable();
        $this->assertSame('CAD', $group->fresh()->currency);
    }

    public function test_preparation_lists_supported_currencies_but_eur_activation_is_off(): void
    {
        config(['payments.eur_enabled' => false]);
        $this->actingAs(User::factory()->create())->get('/dashboard/groups/create')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('supportedCurrencies', ['CAD', 'EUR'])
            ->where('enabledCurrencies', ['CAD']));
        $this->assertSame(['CAD'], BillingCurrencies::enabled());
        config(['payments.eur_enabled' => true]);
        $this->assertSame(['CAD', 'EUR'], BillingCurrencies::enabled());
    }

    public function test_owner_can_save_and_resume_an_eur_draft_without_financial_side_effects(): void
    {
        $owner = User::factory()->create(['identity_status' => 'unverified', 'stripe_connect_status' => 'not_started']);
        $data = [...$this->validDraftData(), 'currency' => 'EUR', 'total_price' => 1859];
        $id = (string) Str::uuid();
        $this->actingAs($owner)->postJson('/group-drafts', ['id' => $id, 'data' => $data])
            ->assertCreated()->assertJsonPath('draft.data.currency', 'EUR')
            ->assertJsonPath('draft.preview.currency', 'EUR')->assertJsonPath('draft.preview.total_price', 1859);
        $this->getJson('/group-drafts/'.$id)->assertOk()->assertJsonPath('draft.data', $data);
        $this->assertSame($data, GroupDraft::findOrFail($id)->data);
        $this->assertNoDraftSideEffects();
    }

    public function test_eur_publication_is_rejected_before_stripe_while_activation_is_off(): void
    {
        config(['payments.eur_enabled' => false]);
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, [...$this->validDraftData(), 'currency' => 'EUR']);
        $this->ownerStripe->shouldNotReceive('retrieveAccount', 'retrieveIdentity');

        $this->actingAs($owner)->postJson('/group-drafts/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoDraftSideEffects();
    }

    public function test_enabled_eur_publication_persists_the_declared_currency_and_minor_units(): void
    {
        config(['payments.eur_enabled' => true]);
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, [...$this->validDraftData(), 'currency' => 'EUR', 'total_price' => 1899]);
        $this->productGateway->shouldReceive('ensureProduct')->once()->andReturn('prod_eur_group');

        $this->actingAs($owner)->postJson('/group-drafts/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])->assertOk();
        $group = Group::sole();
        $this->assertSame('EUR', $group->currency);
        $this->assertSame('CAD', $group->subscription->currency);
        $this->assertSame(1899, $group->total_price);
        $this->assertSame('EUR', $group->stripePrice->currency);
        $this->assertSame(1899, $group->members()->sole()->share_amount);
    }

    public static function invalidCurrencies(): array
    {
        return ['unknown' => ['USD'], 'lowercase input' => ['eur'], 'missing explicit value' => [''], 'null' => [null], 'array' => [['EUR']]];
    }

    public function test_legacy_draft_currency_is_frozen_before_the_product_request(): void
    {
        $owner = $this->readyOwner();
        $subscription = Subscription::factory()->create(['currency' => 'CAD']);
        $data = $this->validDraftData($subscription);
        unset($data['currency']);
        $draft = $this->draftFor($owner, $data);
        $this->productGateway->shouldReceive('ensureProduct')->once()->andReturnUsing(function () use ($draft, $subscription): string {
            $this->assertSame('CAD', $draft->fresh()->data['currency']);
            $subscription->update(['currency' => 'EUR']);

            return 'prod_legacy_currency';
        });

        $this->actingAs($owner)->postJson('/group-drafts/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])->assertOk();
        $this->assertSame('CAD', Group::sole()->currency);
        $this->assertSame($data['total_price'], Group::sole()->total_price);
    }

    #[DataProvider('draftEndpoints')]
    public function test_save_without_currency_preserves_the_existing_snapshot_even_when_service_changes(string $base): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, [...$this->validDraftData(), 'currency' => 'EUR', 'total_price' => 1859]);
        $replacement = Subscription::factory()->create(['currency' => 'CAD']);
        $data = [...$draft->data, 'subscription_id' => $replacement->id];
        unset($data['currency']);
        $this->authenticateDraftOwner($owner, $base);

        $this->putJson($base.'/'.$draft->id, ['version' => 1, 'data' => $data])->assertOk()
            ->assertJsonPath('draft.data.currency', 'EUR')->assertJsonPath('draft.preview.currency', 'EUR');
        $this->assertSame([...$data, 'currency' => 'EUR'], $draft->fresh()->data);
        $this->assertSame(1859, $draft->fresh()->data['total_price']);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_legacy_save_freezes_original_service_currency_before_replacing_service(string $base): void
    {
        $owner = User::factory()->create();
        $original = Subscription::factory()->create(['currency' => 'CAD']);
        $replacement = Subscription::factory()->create(['currency' => 'EUR']);
        $data = [...$this->validDraftData($original), 'total_price' => 1859];
        unset($data['currency']);
        $draft = $this->draftFor($owner, $data);
        $data['subscription_id'] = $replacement->id;
        $this->authenticateDraftOwner($owner, $base);

        $this->putJson($base.'/'.$draft->id, ['version' => 1, 'data' => $data])->assertOk()
            ->assertJsonPath('draft.data.currency', 'CAD')->assertJsonPath('draft.preview.currency', 'CAD');
        $original->update(['currency' => 'EUR']);
        $this->getJson($base.'/'.$draft->id)->assertOk()->assertJsonPath('draft.preview.currency', 'CAD');
        $this->assertSame([...$data, 'currency' => 'CAD'], $draft->fresh()->data);
        $this->assertNoDraftSideEffects();
    }

    public static function partialDrafts(): array
    {
        return [['name' => 'Sans service'], ['name' => 'Montant à préciser', 'total_price' => 99]];
    }

    #[DataProvider('partialDrafts')]
    public function test_partial_legacy_draft_can_still_save_without_service_or_invented_currency(string $name, ?int $total_price = null): void
    {
        $owner = User::factory()->create();
        $data = ['name' => $name];
        if ($total_price !== null) {
            $data['total_price'] = $total_price;
        }
        $draft = $this->draftFor($owner, $data);
        $data['description'] = 'Préparation en cours';

        $this->actingAs($owner)->putJson('/group-drafts/'.$draft->id, ['version' => 1, 'data' => $data])
            ->assertOk()->assertJsonPath('draft.data', $data)->assertJsonPath('draft.preview', null);
        $this->assertSame($data, $draft->fresh()->data);
        $this->assertNoDraftSideEffects();
    }

    public function test_legacy_amount_without_currency_cannot_silently_inherit_a_new_services_currency(): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Montant sans devise', 'total_price' => 1859]);
        $data = [...$this->validDraftData(Subscription::factory()->create(['currency' => 'EUR'])), 'total_price' => 1859];
        unset($data['currency']);

        $this->actingAs($owner)->putJson('/group-drafts/'.$draft->id, ['version' => 1, 'data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data.currency');
        $this->assertSame($draft->data, $draft->fresh()->data);
        $this->assertSame(1, $draft->fresh()->version);
        $this->putJson('/group-drafts/'.$draft->id, ['version' => 1, 'data' => [...$data, 'currency' => 'EUR']])
            ->assertOk()->assertJsonPath('draft.data.currency', 'EUR')->assertJsonPath('draft.data.total_price', 1859);
        $this->assertNoDraftSideEffects();
    }

    public function test_partial_draft_without_amount_can_take_its_first_service_currency(): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Préparation']);
        $data = $this->validDraftData(Subscription::factory()->create(['currency' => 'EUR']));
        unset($data['currency']);

        $this->actingAs($owner)->putJson('/group-drafts/'.$draft->id, ['version' => 1, 'data' => $data])
            ->assertOk()->assertJsonPath('draft.data.currency', 'EUR');
        $this->assertSame([...$data, 'currency' => 'EUR'], $draft->fresh()->data);
        $this->assertNoDraftSideEffects();
    }

    public function test_legacy_currency_is_persisted_before_readiness_refresh_changes_catalogue(): void
    {
        config(['payments.eur_enabled' => true]);
        $owner = $this->readyOwner();
        $subscription = Subscription::factory()->create(['currency' => 'CAD']);
        $data = $this->validDraftData($subscription);
        unset($data['currency']);
        $draft = $this->draftFor($owner, $data);
        $outerTransactionLevel = DB::transactionLevel();
        $observed = [];
        $this->ownerStripe->shouldReceive('retrieveAccount')->once()->with('acct_draft_test')
            ->andReturnUsing(function () use ($draft, $subscription, &$observed): OwnerConnectState {
                $observed = [$draft->fresh()->data['currency'] ?? null, DB::transactionLevel()];
                $subscription->update(['currency' => 'EUR']);

                return new OwnerConnectState('acct_draft_test', true, true, true);
            });
        $this->productGateway->shouldReceive('ensureProduct')->once()->andReturnUsing(function () use ($outerTransactionLevel): string {
            $this->assertSame($outerTransactionLevel, DB::transactionLevel());

            return 'prod_stable_currency';
        });

        $this->actingAs($owner)->postJson('/group-drafts/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])->assertOk();
        $this->assertSame(['CAD', $outerTransactionLevel], $observed);
        $this->assertSame('CAD', Group::sole()->currency);
        $this->assertSame($data['total_price'], Group::sole()->total_price);
    }

    public function test_readiness_failure_retry_retains_legacy_currency_despite_catalogue_change(): void
    {
        config(['payments.eur_enabled' => true]);
        $owner = $this->readyOwner();
        $subscription = Subscription::factory()->create(['currency' => 'CAD']);
        $data = $this->validDraftData($subscription);
        unset($data['currency']);
        $draft = $this->draftFor($owner, $data);
        $calls = 0;
        $this->ownerStripe->shouldReceive('retrieveAccount')->twice()->with('acct_draft_test')
            ->andReturnUsing(function () use (&$calls): OwnerConnectState {
                if (++$calls === 1) {
                    throw new RuntimeException('Temporary readiness failure.');
                }

                return new OwnerConnectState('acct_draft_test', true, true, true);
            });
        $this->productGateway->shouldReceive('ensureProduct')->once()->with($draft->id, $data['name'], $owner->id, 1)->andReturn('prod_retry_stable');
        $payload = ['version' => 1, 'certify' => true];
        $this->actingAs($owner)->postJson('/group-drafts/'.$draft->id.'/publish', $payload)->assertStatus(503);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertNoPublishedEffects();
        $subscription->update(['currency' => 'EUR']);

        $this->postJson('/group-drafts/'.$draft->id.'/publish', $payload)->assertOk();
        $this->assertSame('CAD', $draft->fresh()->data['currency']);
        $this->assertSame('CAD', Group::sole()->currency);
        $this->assertSame($data['total_price'], Group::sole()->total_price);
    }

    public function test_stale_publication_version_is_rechecked_under_lock_before_any_stripe_call(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $newData = [...$draft->data, 'currency' => 'EUR', 'total_price' => 2101];
        app(GroupDraftService::class)->save($owner, $draft, 1, $newData);
        $stripeReads = 0;
        $this->ownerStripe->shouldReceive('retrieveAccount')->with('acct_draft_test')
            ->andReturnUsing(function () use (&$stripeReads): OwnerConnectState {
                $stripeReads++;

                return new OwnerConnectState('acct_draft_test', true, true, true);
            });

        try {
            app(PublishGroupDraft::class)->publish($owner, $draft, 1, []);
            $this->fail('The stale publication must fail before Stripe.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, $stripeReads);
            $this->assertSame($newData, $draft->fresh()->data);
            $this->assertSame(2, $draft->fresh()->version);
            $this->assertNoPublishedEffects();
        }
    }

    #[DataProvider('invalidCurrencies')]
    public function test_draft_rejects_invalid_currency_values(mixed $currency): void
    {
        $this->actingAs(User::factory()->create())->postJson('/group-drafts', [
            'id' => (string) Str::uuid(), 'data' => ['currency' => $currency],
        ])->assertUnprocessable()->assertJsonValidationErrors('data.currency');
        $this->assertDatabaseCount('group_drafts', 0);
    }
}
