<?php

namespace Tests\Feature;

use App\Features\Group\Exceptions\PublicationUnavailable;
use App\Features\Group\Services\GroupDraftService;
use App\Features\Group\Services\PublishGroupDraft;
use App\Features\Payment\DTO\OwnerConnectState;
use App\Features\Payment\DTO\OwnerIdentityState;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GroupDraftTestCase;

class GroupDraftPublicationTest extends GroupDraftTestCase
{
    #[DataProvider('draftEndpoints')]
    public function test_publication_commits_one_group_price_and_owner_membership_then_replays_the_result(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $outerTransactionLevel = DB::transactionLevel();
        $this->ownerStripe->shouldReceive('retrieveAccount')->once()->with('acct_draft_test')
            ->andReturn(new OwnerConnectState('acct_draft_test', true, true, true));
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($draft, $outerTransactionLevel): string {
                $this->assertSame('publishing', $draft->fresh()->status);
                $this->assertSame($draft->data, $draft->fresh()->data);
                $this->assertNull($draft->fresh()->published_group_id);
                $this->assertNoPublishedEffects();
                // RefreshDatabase owns the outer test transaction; publication must not add a network lock.
                $this->assertSame($outerTransactionLevel, DB::transactionLevel());

                return 'prod_draft_confirmed';
            });

        $payload = ['version' => 1, 'certify' => true];
        $published = $this->postJson("{$base}/{$draft->id}/publish", $payload)->assertSuccessful()
            ->assertJsonStructure(['group_id', 'redirect']);
        $group = Group::sole();
        $this->assertSame($group->id, $published->json('group_id'));
        $this->assertIsString($published->json('redirect'));
        $this->assertSame('/dashboard/subscriptions?tab=owned', $published->json('redirect'));
        $this->assertSame($draft->id, $group->uuid);
        $this->assertSame($owner->id, $group->owner_id);
        $this->assertSame($draft->data['name'], $group->name);
        $this->assertSame('open', $group->status);
        $this->assertSame(1, $group->current_members);
        $this->assertSame(1999, (int) $group->total_price);
        $this->assertDatabaseHas('stripe_prices', [
            'group_id' => $group->id,
            'stripe_product_id' => 'prod_draft_confirmed',
            'stripe_price_id' => null,
            'unit_amount' => 1999,
            'currency' => 'CAD',
        ]);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertSame($group->id, $draft->fresh()->published_group_id);

        // Both submissions still carry the original version, as two browser tabs would.
        $this->postJson("{$base}/{$draft->id}/publish", $payload)->assertOk()
            ->assertJsonPath('group_id', $group->id)
            ->assertJsonPath('redirect', $published->json('redirect'));
        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('stripe_prices', 1);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    #[DataProvider('draftEndpoints')]
    public function test_product_failure_keeps_a_safe_snapshot_and_retry_uses_the_same_draft_without_duplicates(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $snapshot = $draft->data;
        $this->authenticateDraftOwner($owner, $base);
        $attempt = 0;
        $this->productGateway->shouldReceive('ensureProduct')->twice()
            ->with($draft->id, $snapshot['name'], $owner->id, 1)
            ->andReturnUsing(function () use (&$attempt, $draft, $snapshot): string {
                $this->assertSame('publishing', $draft->fresh()->status);
                $this->assertSame($snapshot, $draft->fresh()->data);
                $this->assertNoPublishedEffects();
                if (++$attempt === 1) {
                    // Models a lost product response: the external product may already exist.
                    throw new PublicationUnavailable;
                }

                return 'prod_recovered_by_draft_uuid';
            });
        $payload = ['version' => 1, 'certify' => true];

        $this->postJson("{$base}/{$draft->id}/publish", $payload)->assertStatus(503);
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertNull($draft->fresh()->published_group_id);
        $this->assertNoPublishedEffects();

        $this->putJson("{$base}/{$draft->id}", ['version' => 1, 'data' => ['name' => 'Changed during retry']])
            ->assertConflict();
        $this->deleteJson("{$base}/{$draft->id}", ['version' => 1])->assertConflict();
        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 2, 'certify' => true])->assertConflict();
        $published = $this->postJson("{$base}/{$draft->id}/publish", $payload)->assertSuccessful();
        $this->postJson("{$base}/{$draft->id}/publish", $payload)->assertOk()
            ->assertJsonPath('group_id', $published->json('group_id'));

        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseCount('stripe_prices', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('stripe_prices', ['stripe_product_id' => 'prod_recovered_by_draft_uuid']);
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertSame($snapshot, $draft->fresh()->data);
    }

    public static function publicationValidationCases(): iterable
    {
        $cases = [
            'incomplete' => ['only_name', 'subscription_id'],
            'missing service' => [['subscription_id' => 999999], 'subscription_id'],
            'inactive service' => ['inactive_subscription', 'subscription_id'],
            'service capacity' => [['max_members' => 7], 'max_members'],
            'absolute capacity' => ['above_ten', 'max_members'],
            'minimum capacity' => [['max_members' => 1], 'max_members'],
            'minimum price' => [['total_price' => 99], 'total_price'],
            'fractional cents' => [['total_price' => 1999.5], 'total_price'],
            'today' => ['today', 'renewal_date'],
            'past renewal' => ['yesterday', 'renewal_date'],
            'missing name' => [['name' => ''], 'name'],
            'invalid visibility' => [['visibility' => 'everyone'], 'visibility'],
            'invalid tier' => [['tier' => 'invented'], 'tier'],
            'invalid split' => [['split_type' => 'invented'], 'split_type'],
        ];
        foreach (self::draftEndpoints() as $surface => [$base]) {
            foreach ($cases as $name => [$change, $field]) {
                yield "{$surface}: {$name}" => [$base, $change, $field];
            }
        }
    }

    #[DataProvider('publicationValidationCases')]
    public function test_publication_revalidates_persisted_data_against_current_store_rules(string $base, array|string $change, string $field): void
    {
        $owner = $this->readyOwner();
        $subscription = Subscription::factory()->create(['max_members' => $change === 'above_ten' ? 15 : 6]);
        $data = $this->validDraftData($subscription);
        $data = match ($change) {
            'only_name' => ['name' => 'Incomplet'],
            'above_ten' => [...$data, 'max_members' => 11],
            'today' => [...$data, 'renewal_date' => now()->toDateString()],
            'yesterday' => [...$data, 'renewal_date' => now()->subDay()->toDateString()],
            'inactive_subscription' => $data,
            default => [...$data, ...$change],
        };
        $draft = $this->draftFor($owner, $data);
        if ($change === 'inactive_subscription') {
            $subscription->update(['is_active' => false]);
        }
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->published_group_id);
        $this->assertNoPublishedEffects();
    }

    public static function notReadyOwners(): iterable
    {
        foreach (self::draftEndpoints() as $surface => [$base]) {
            foreach ([
                'identity' => ['identity_status' => 'unverified'],
                'identity pending' => ['identity_status' => 'pending'],
                'connect pending' => ['stripe_connect_status' => 'pending'],
                'connect absent' => ['stripe_connect_status' => 'not_started', 'stripe_connect_account_id' => null],
            ] as $name => $attributes) {
                yield "{$surface}: {$name}" => [$base, $attributes];
            }
        }
    }

    #[DataProvider('notReadyOwners')]
    public function test_publication_requires_identity_and_connect_readiness_before_product_creation(string $base, array $attributes): void
    {
        $owner = $this->readyOwner($attributes);
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])
            ->assertUnprocessable();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_readiness_is_refreshed_before_trusting_a_locally_active_account(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->ownerStripe->shouldReceive('retrieveAccount')->once()->with('acct_draft_test')
            ->andReturn(new OwnerConnectState('acct_draft_test', false, false, true));

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])
            ->assertUnprocessable();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_a_save_during_readiness_refresh_invalidates_publication_before_product_creation(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $savedData = [...$draft->data, 'name' => 'Modification depuis un second onglet'];
        $this->ownerStripe->shouldReceive('retrieveAccount')->once()->with('acct_draft_test')
            ->andReturnUsing(function () use ($owner, $draft, $savedData): OwnerConnectState {
                app(GroupDraftService::class)->save($owner, $draft->fresh(), 1, $savedData);

                return new OwnerConnectState('acct_draft_test', true, true, true);
            });

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertConflict();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(2, $draft->fresh()->version);
        $this->assertSame($savedData, $draft->fresh()->data);
        $this->assertNoPublishedEffects();
    }

    public static function publicationDataChangesDuringProductCall(): iterable
    {
        foreach (self::draftEndpoints() as $surface => [$base]) {
            foreach (['inactive subscription', 'reduced capacity', 'expired renewal'] as $change) {
                yield "{$surface}: {$change}" => [$base, $change];
            }
        }
    }

    #[DataProvider('publicationDataChangesDuringProductCall')]
    public function test_catalogue_and_renewal_are_rechecked_after_the_product_response(string $base, string $change): void
    {
        $owner = $this->readyOwner();
        $subscription = Subscription::factory()->create(['max_members' => 6]);
        $draft = $this->draftFor($owner, $this->validDraftData($subscription));
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($subscription, $change): string {
                match ($change) {
                    'inactive subscription' => $subscription->update(['is_active' => false]),
                    'reduced capacity' => $subscription->update(['max_members' => 3]),
                    'expired renewal' => $this->travelTo(now()->addDays(32)),
                };

                return 'prod_confirmed_before_catalogue_changed';
            });
        $field = match ($change) {
            'inactive subscription' => 'subscription_id',
            'reduced capacity' => 'max_members',
            'expired renewal' => 'renewal_date',
        };

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertNull($draft->fresh()->published_group_id);
        $this->assertNoPublishedEffects();
    }

    public static function readinessChangesDuringProductCall(): iterable
    {
        foreach (self::draftEndpoints() as $surface => [$base]) {
            foreach ([
                'suspension' => [['status' => 'suspended'], [403]],
                'identity revoked' => [['identity_status' => 'unverified'], [422]],
                'connect restricted' => [['stripe_connect_status' => 'pending'], [422]],
                'email revoked' => [['email_verified_at' => null], [403, 409]],
            ] as $name => [$attributes, $statuses]) {
                yield "{$surface}: {$name}" => [$base, $attributes, $statuses];
            }
        }
    }

    #[DataProvider('readinessChangesDuringProductCall')]
    public function test_a_changed_owner_state_between_product_creation_and_commit_prevents_opening(string $base, array $attributes, array $statuses): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($owner, $attributes): string {
                User::whereKey($owner->id)->update($attributes);

                return 'prod_created_before_readiness_changed';
            });

        $response = $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true]);
        $this->assertContains($response->status(), $statuses);
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->published_group_id);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_publication_requires_current_version_and_explicit_certification(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, attributes: ['version' => 2]);
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertConflict();
        foreach ([[], ['certify' => false]] as $certification) {
            $this->postJson("{$base}/{$draft->id}/publish", ['version' => 2, ...$certification])
                ->assertUnprocessable()->assertJsonValidationErrors('certify');
        }
        $this->postJson("{$base}/{$draft->id}/publish", ['certify' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_publish_cannot_replace_the_persisted_snapshot_with_client_data_or_readiness(string $base): void
    {
        $owner = $this->readyOwner(['identity_status' => 'unverified', 'stripe_connect_status' => 'pending']);
        $draft = $this->draftFor($owner, ['name' => 'Incomplet côté serveur']);
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson("{$base}/{$draft->id}/publish", [
            'version' => 1,
            'certify' => true,
            'data' => $this->validDraftData(),
            'owner_id' => $this->readyOwner()->id,
            'identity_status' => 'verified',
            'stripe_connect_status' => 'active',
            'ownerReadiness' => ['canPublish' => true],
            'status' => 'published',
        ])->assertUnprocessable();
        $this->assertSame(['name' => 'Incomplet côté serveur'], $draft->fresh()->data);
        $this->assertSame($owner->id, $draft->fresh()->owner_id);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_credentials_are_encrypted_only_on_the_group_and_never_returned_or_saved_in_the_draft(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $secrets = [
            'credential_email' => 'publication-secret@example.test',
            'credential_password' => 'publication-secret-password-942',
            'credential_notes' => 'publication-secret-notes-942',
        ];
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)->andReturn('prod_encrypted_group');

        $published = $this->postJson("{$base}/{$draft->id}/publish", [
            'version' => 1, 'certify' => true, ...$secrets,
        ])->assertSuccessful();
        $group = Group::sole();
        $raw = DB::table('groups')->where('id', $group->id)->first();
        foreach ($secrets as $key => $secret) {
            $this->assertSame($secret, $group->{$key});
            $this->assertNotEmpty($raw->{$key});
            $this->assertNotSame($secret, $raw->{$key});
        }
        $replayed = $this->postJson("{$base}/{$draft->id}/publish", [
            'version' => 1, 'certify' => true, ...$secrets,
        ])->assertOk();
        $public = $this->getJson("/api/groups/{$group->id}")->assertOk();
        $this->assertSecretsAbsent($draft, $secrets, $published, $replayed, $public);
    }

    #[DataProvider('draftEndpoints')]
    public function test_publication_failure_does_not_persist_or_flash_submitted_credentials(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $secrets = [
            'credential_email' => 'failed-publication@example.test',
            'credential_password' => 'failed-publication-secret-943',
            'credential_notes' => 'failed-publication-notes-943',
        ];
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andThrow(new PublicationUnavailable);

        $response = $this->postJson("{$base}/{$draft->id}/publish", [
            'version' => 1, 'certify' => true, ...$secrets,
        ])->assertStatus(503);
        $this->assertSecretsAbsent($draft, $secrets, $response);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_invalid_credentials_are_rejected_before_product_creation_without_echoing_secrets(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $secrets = [
            'credential_email' => 'invalid-email-secret-944',
            'credential_password' => str_repeat('secret-password-', 20),
            'credential_notes' => str_repeat('secret-notes-', 100),
        ];

        $response = $this->postJson("{$base}/{$draft->id}/publish", [
            'version' => 1, 'certify' => true, ...$secrets,
        ])->assertUnprocessable()->assertJsonValidationErrors(array_keys($secrets));
        $this->assertSecretsAbsent($draft, $secrets, $response);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_a_published_draft_is_immutable_and_cannot_be_deleted(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)->andReturn('prod_immutable');
        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertSuccessful();
        $group = Group::sole();
        $version = $draft->fresh()->version;

        $this->putJson("{$base}/{$draft->id}", ['version' => $version, 'data' => ['name' => 'Overwritten']])
            ->assertConflict();
        $this->deleteJson("{$base}/{$draft->id}", ['version' => $version])->assertConflict();
        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => $version])->assertConflict();
        $this->assertSame($draft->data['name'], $group->fresh()->name);
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('stripe_prices', 1);
        $this->assertDatabaseCount('group_members', 1);
    }

    #[DataProvider('draftEndpoints')]
    public function test_reopen_during_product_creation_invalidates_the_old_attempt_and_uses_a_new_product_version(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($owner, $draft): string {
                $reopened = app(GroupDraftService::class)->reopen($owner, $draft->fresh(), 1);
                $this->assertSame(2, $reopened->version);
                $this->assertSame('draft', $reopened->status);

                return 'prod_abandoned_version_1';
            });

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertConflict();
        $this->assertNoPublishedEffects();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(2, $draft->fresh()->version);
        $this->assertNull($draft->fresh()->published_group_id);

        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 2)
            ->andReturn('prod_confirmed_version_2');
        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 2, 'certify' => true])->assertOk();
        $this->assertSame($draft->id, Group::sole()->uuid);
        $this->assertDatabaseHas('stripe_prices', ['stripe_product_id' => 'prod_confirmed_version_2']);
        $this->assertDatabaseMissing('stripe_prices', ['stripe_product_id' => 'prod_abandoned_version_1']);
        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('published', $draft->fresh()->status);
        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertConflict();
        $this->assertDatabaseCount('groups', 1);
    }

    #[DataProvider('draftEndpoints')]
    public function test_a_cancelled_attempt_cannot_report_success_after_a_newer_version_publishes(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 2)->andReturn('prod_winning_version_2');
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($owner, $draft): string {
                app(GroupDraftService::class)->reopen($owner, $draft->fresh(), 1);
                app(PublishGroupDraft::class)->publish($owner, $draft->fresh(), 2, []);

                return 'prod_cancelled_version_1';
            });

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertConflict();
        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('stripe_prices', 1);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseHas('stripe_prices', ['stripe_product_id' => 'prod_winning_version_2']);
        $this->assertDatabaseMissing('stripe_prices', ['stripe_product_id' => 'prod_cancelled_version_1']);
        $this->assertSame(2, $draft->fresh()->version);
        $this->assertSame('published', $draft->fresh()->status);
    }

    #[DataProvider('draftEndpoints')]
    public function test_reopening_then_deleting_during_publication_permanently_invalidates_the_old_attempt(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($owner, $draft): string {
                $service = app(GroupDraftService::class);
                $reopened = $service->reopen($owner, $draft->fresh(), 1);
                $service->delete($owner, $reopened, $reopened->version);
                try {
                    $service->create($owner, $draft->id, $draft->data);
                    $this->fail('A deleted UUID was recreated while its old publication was in flight.');
                } catch (HttpException $e) {
                    $this->assertSame(404, $e->getStatusCode());
                }

                return 'prod_cancelled_deleted_draft';
            });

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertNotFound();
        $this->assertSoftDeleted('group_drafts', ['id' => $draft->id]);
        $this->assertNoPublishedEffects();
        $this->postJson($base, ['id' => $draft->id, 'data' => $draft->data])->assertNotFound();
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_interleaved_publications_of_the_same_version_return_the_same_group(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $attempt = 0;
        $winningId = null;
        $this->productGateway->shouldReceive('ensureProduct')->twice()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)
            ->andReturnUsing(function () use ($owner, $draft, &$attempt, &$winningId): string {
                if (++$attempt === 1) {
                    $winningId = app(PublishGroupDraft::class)->publish($owner, $draft->fresh(), 1, [])->id;
                }

                return 'prod_same_version';
            });

        $response = $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertOk();
        $this->assertSame($winningId, $response->json('group_id'));
        $this->assertSame($winningId, $draft->fresh()->published_group_id);
        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('stripe_prices', 1);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    #[DataProvider('draftEndpoints')]
    public function test_expired_publishing_snapshot_can_be_reopened_corrected_and_published(string $base): void
    {
        $owner = $this->readyOwner();
        $data = [...$this->validDraftData(), 'renewal_date' => now()->subDay()->toDateString()];
        $draft = $this->draftFor($owner, $data, ['status' => 'publishing']);
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('renewal_date');
        $this->assertNoPublishedEffects();
        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 1])->assertOk()
            ->assertJsonPath('draft.version', 2);
        $data['renewal_date'] = now()->addMonth()->toDateString();
        $this->putJson("{$base}/{$draft->id}", ['version' => 2, 'data' => $data])->assertOk()
            ->assertJsonPath('draft.version', 3);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $data['name'], $owner->id, 3)->andReturn('prod_corrected_version_3');

        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 3, 'certify' => true])->assertOk();
        $this->assertSame($data['renewal_date'], Group::sole()->renewal_date->toDateString());
        $this->assertDatabaseHas('stripe_prices', ['stripe_product_id' => 'prod_corrected_version_3']);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_web_validation_failure_does_not_flash_credentials_to_the_session(): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->actingAs($owner);
        $secrets = [
            'credential_email' => 'form-validation-secret@example.test',
            'credential_password' => 'form-validation-password-945',
            'credential_notes' => 'form-validation-notes-945',
        ];

        $response = $this->from("/dashboard/groups/drafts/{$draft->id}")
            ->post("/group-drafts/{$draft->id}/publish", ['version' => 1, 'certify' => false, ...$secrets]);
        $this->assertContains($response->status(), [302, 422]);
        $this->assertSecretsAbsent($draft, $secrets, $response);
        $this->assertNoPublishedEffects();
    }

    public function test_legacy_create_failure_preserves_safe_input_without_credentials_or_unvalidated_fields(): void
    {
        $owner = $this->readyOwner(['identity_status' => 'unverified']);
        $this->ownerStripe->shouldReceive('retrieveIdentity')->once()->with($owner->stripe_identity_session_id)
            ->andReturn(new OwnerIdentityState($owner->stripe_identity_session_id, 'requires_input'));
        $draft = $this->draftFor($owner);
        $this->actingAs($owner);
        $secrets = [
            'credential_email' => 'legacy-form-secret@example.test',
            'credential_password' => 'legacy-form-password-948',
            'credential_notes' => 'legacy-form-notes-948',
        ];

        $response = $this->from('/dashboard/groups/create')->post('/groups', [
            ...$draft->data,
            ...$secrets,
            'unexpected' => ['credential_password' => 'legacy-unvalidated-password-948'],
        ])->assertRedirect('/dashboard/groups/create');
        $response->assertSessionHasInput('name', $draft->data['name']);
        $this->assertSecretsAbsent($draft, [...$secrets, 'legacy-unvalidated-password-948'], $response);
        $this->assertNoPublishedEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_a_local_membership_failure_rolls_back_group_price_and_publication_atomically(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()
            ->with($draft->id, $draft->data['name'], $owner->id, 1)->andReturn('prod_before_local_failure');
        // Fault injection at the final local write, after the confirmed external product.
        GroupMember::creating(function (): void {
            throw new RuntimeException('Injected membership persistence failure');
        });
        $this->withoutExceptionHandling();
        try {
            try {
                $response = $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true]);
                $this->assertContains($response->status(), [500, 503]);
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected membership persistence failure', $exception->getMessage());
            }
            $this->assertSame('publishing', $draft->fresh()->status);
            $this->assertNull($draft->fresh()->published_group_id);
            $this->assertNoPublishedEffects();
        } finally {
            GroupMember::flushEventListeners();
        }
    }
}
