<?php

namespace Tests\Feature;

use App\Features\Auth\Services\AccountSession;
use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupDraftTestCase;

class GroupDraftTest extends GroupDraftTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerStripe->shouldNotReceive('retrieveAccount', 'retrieveIdentity');
    }

    public function test_create_page_exposes_the_real_active_catalogue_before_identity_and_connect(): void
    {
        $owner = User::factory()->create([
            'identity_status' => 'unverified',
            'stripe_connect_status' => 'not_started',
        ]);
        $subscription = Subscription::factory()->create(['monthly_price' => 2345, 'max_members' => 5]);
        Subscription::factory()->create(['is_active' => false]);

        $this->actingAs($owner)->get('/dashboard/groups/create')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Create')
            ->where('draft', null)
            ->has('ownerReadiness')
            ->has('subscriptions', 1)
            ->where('subscriptions.0.id', $subscription->id)
            ->where('subscriptions.0.name', $subscription->name)
            ->where('subscriptions.0.monthly_price', 2345)
            ->where('subscriptions.0.max_members', 5));

        $this->assertDatabaseCount('group_drafts', 0);
        $this->assertNoDraftSideEffects();
    }

    public function test_owner_can_resume_an_incomplete_draft_without_identity_or_connect(): void
    {
        $owner = User::factory()->create([
            'identity_status' => 'unverified',
            'stripe_connect_status' => 'not_started',
        ]);
        $data = ['name' => 'Travail en cours'];
        $draft = $this->draftFor($owner, $data);

        $this->actingAs($owner)->get("/dashboard/groups/drafts/{$draft->id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Create')
            ->where('draft.id', $draft->id)
            ->where('draft.version', 1)
            ->where('draft.data.name', $data['name'])
            ->has('subscriptions')
            ->has('ownerReadiness'));
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_partial_save_is_private_and_has_no_financial_or_membership_effect(string $base): void
    {
        $owner = User::factory()->create([
            'identity_status' => 'unverified',
            'stripe_connect_status' => 'not_started',
        ]);
        $this->authenticateDraftOwner($owner, $base);
        $id = (string) Str::uuid();
        $data = ['name' => 'Pas encore complet', 'total_price' => 99];

        $this->postJson($base, ['id' => $id, 'data' => $data])
            ->assertCreated()
            ->assertJsonPath('draft.id', $id)
            ->assertJsonPath('draft.version', 1)
            ->assertJsonPath('draft.status', 'draft')
            ->assertJsonPath('draft.published_group_id', null)
            ->assertJsonPath('draft.data', $data)
            ->assertJsonStructure(['draft' => ['updated_at']]);

        $draft = GroupDraft::findOrFail($id);
        $this->assertSame($owner->id, $draft->owner_id);
        $this->assertSame($data, $draft->data);
        $this->assertDatabaseCount('group_drafts', 1);
        $this->assertNull($owner->fresh()->stripe_connect_account_id);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_create_retry_is_idempotent_but_changed_payload_conflicts(string $base): void
    {
        $owner = User::factory()->create();
        $this->authenticateDraftOwner($owner, $base);
        $payload = ['id' => (string) Str::uuid(), 'data' => ['name' => 'Première saisie']];
        $first = $this->postJson($base, $payload)->assertCreated();

        $this->postJson($base, $payload)->assertOk()
            ->assertJsonPath('draft', $first->json('draft'));
        $this->postJson($base, [...$payload, 'data' => ['name' => 'Autre saisie']])->assertConflict();

        $this->assertSame($payload['data'], GroupDraft::findOrFail($payload['id'])->data);
        $this->assertDatabaseCount('group_drafts', 1);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_update_increments_version_and_a_second_tab_cannot_overwrite_it(string $base): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Original']);
        $this->authenticateDraftOwner($owner, $base);
        $payload = ['version' => 1, 'data' => ['name' => 'Onglet A']];

        $this->putJson("{$base}/{$draft->id}", $payload)->assertOk()
            ->assertJsonPath('draft.version', 2)
            ->assertJsonPath('draft.data.name', 'Onglet A');
        $this->putJson("{$base}/{$draft->id}", $payload)->assertConflict();
        $this->putJson("{$base}/{$draft->id}", [
            'version' => 1, 'data' => ['name' => 'Onglet B'],
        ])->assertConflict();
        $this->assertSame(['name' => 'Onglet A'], $draft->fresh()->data);
        $this->assertSame(2, $draft->fresh()->version);

        $this->putJson("{$base}/{$draft->id}", [
            'version' => 2, 'data' => ['name' => 'Après rechargement'],
        ])->assertOk()->assertJsonPath('draft.version', 3);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_delete_requires_the_current_version_and_removes_only_the_draft(string $base): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'À supprimer'], ['version' => 2]);
        $otherDraft = $this->draftFor($owner, ['name' => 'À conserver']);
        $this->authenticateDraftOwner($owner, $base);

        $this->deleteJson("{$base}/{$draft->id}", ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('group_drafts', ['id' => $draft->id, 'version' => 2]);
        $this->deleteJson("{$base}/{$draft->id}", ['version' => 2])->assertNoContent();
        $this->assertSoftDeleted('group_drafts', ['id' => $draft->id]);
        $this->assertNull(GroupDraft::find($draft->id));
        $this->assertSame([], GroupDraft::withTrashed()->findOrFail($draft->id)->data);
        $this->assertDatabaseHas('group_drafts', ['id' => $otherDraft->id, 'version' => 1]);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_a_deleted_draft_is_hidden_and_its_uuid_cannot_be_reused_even_by_its_owner(string $base): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($owner, $base);
        $this->deleteJson("{$base}/{$draft->id}", ['version' => 1])->assertNoContent();

        $this->getJson($base)->assertOk()->assertJsonCount(0, 'drafts');
        $this->getJson("{$base}/{$draft->id}")->assertNotFound();
        $this->putJson("{$base}/{$draft->id}", ['version' => 1, 'data' => $draft->data])->assertNotFound();
        $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true])->assertNotFound();
        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 1])->assertNotFound();
        $this->deleteJson("{$base}/{$draft->id}", ['version' => 1])->assertNotFound();
        $this->postJson($base, ['id' => $draft->id, 'data' => $draft->data])->assertNotFound();
        $other = User::factory()->create();
        $this->authenticateDraftOwner($other, $base);
        if (! str_starts_with($base, '/api/')) {
            // actingAs switches the guard but does not establish the new
            // account's session as the real login controller does.
            app(AccountSession::class)->remember(app('session.store'), $other);
        }
        $this->postJson($base, ['id' => $draft->id, 'data' => $draft->data])->assertNotFound();

        $this->assertSoftDeleted('group_drafts', ['id' => $draft->id]);
        $this->assertSame([], GroupDraft::withTrashed()->findOrFail($draft->id)->data);
        $this->assertSame(0, GroupDraft::count());
        $this->assertNoDraftSideEffects();
    }

    public function test_production_error_pages_do_not_replace_draft_json_or_redirect_an_expired_session(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.debug' => false]);
        $this->app->instance('env', 'production');
        try {
            $this->get('/group-drafts/'.Str::uuid(), ['Accept' => 'text/html'])
                ->assertNotFound()->assertHeader('Content-Type', 'application/json');
            $this->post('/group-drafts', ['id' => (string) Str::uuid(), 'data' => ['name' => 'Session expirée']], ['Accept' => 'text/html'])
                ->assertStatus(419)->assertHeader('Content-Type', 'application/json');
            $this->assertSame(0, GroupDraft::count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public static function forbiddenDraftFields(): iterable
    {
        $fields = [
            'credential_email' => 'draft-secret@example.test',
            'credential_password' => 'draft-only-secret-731',
            'credential_notes' => 'draft-secret-notes-731',
            'owner_id' => 999,
            'status' => 'published',
            'members' => [['user_id' => 999, 'role' => 'owner']],
            'published_group_id' => 999,
            'version' => 999,
            'stripe_product_id' => 'prod_untrusted',
            'identity_status' => 'verified',
            'stripe_connect_status' => 'active',
            'settings' => ['credential_password' => 'nested-secret'],
            'unexpected_key' => 'must-not-be-persisted',
        ];
        foreach (self::draftEndpoints() as $surface => [$base]) {
            foreach ($fields as $field => $value) {
                yield "{$surface}: {$field}" => [$base, $field, $value];
            }
        }
    }

    #[DataProvider('forbiddenDraftFields')]
    public function test_create_and_update_reject_secret_or_privileged_data_keys(string $base, string $field, mixed $value): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Données sûres']);
        $this->authenticateDraftOwner($owner, $base);
        $data = ['name' => 'Données sûres', $field => $value];

        $create = $this->postJson($base, ['id' => (string) Str::uuid(), 'data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data');
        $update = $this->putJson("{$base}/{$draft->id}", ['version' => 1, 'data' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('data');

        $this->assertSame(['name' => 'Données sûres'], $draft->fresh()->data);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertDatabaseCount('group_drafts', 1);
        if (str_starts_with($field, 'credential_')) {
            $this->assertSecretsAbsent($draft, [$value], $create, $update);
        }
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_malformed_uuid_and_missing_or_invalid_versions_are_rejected(string $base): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Version protégée']);
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson($base, ['id' => '42', 'data' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson($base, ['id' => (string) Str::uuid(), 'data' => 'not-an-array'])
            ->assertUnprocessable()->assertJsonValidationErrors('data');
        foreach ([[], ['version' => 0], ['version' => 'invalid']] as $version) {
            $this->putJson("{$base}/{$draft->id}", [...$version, 'data' => ['name' => 'Remplacement']])
                ->assertUnprocessable()->assertJsonValidationErrors('version');
            $this->deleteJson("{$base}/{$draft->id}", $version)
                ->assertUnprocessable()->assertJsonValidationErrors('version');
        }
        $this->assertSame(['name' => 'Version protégée'], $draft->fresh()->data);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_another_user_cannot_read_mutate_delete_publish_or_claim_the_uuid(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $original = $draft->fresh()->getAttributes();
        $other = $this->readyOwner();
        $this->authenticateDraftOwner($other, $base);

        $show = str_starts_with($base, '/api/')
            ? "{$base}/{$draft->id}" : "/dashboard/groups/drafts/{$draft->id}";
        $responses = [
            $this->getJson($show),
            $this->putJson("{$base}/{$draft->id}", ['version' => 1, 'data' => ['name' => 'Vol']]),
            $this->deleteJson("{$base}/{$draft->id}", ['version' => 1]),
            $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true]),
            $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 1]),
            $this->postJson($base, ['id' => $draft->id, 'data' => $draft->data]),
        ];
        foreach ($responses as $response) {
            $this->assertContains($response->status(), [403, 404]);
            $response->assertDontSee($draft->data['name']);
        }
        $this->assertSame($original, $draft->fresh()->getAttributes());
        $this->assertDatabaseCount('group_drafts', 1);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_index_and_show_expose_only_the_authenticated_owners_drafts(string $base): void
    {
        $owner = User::factory()->create();
        $mine = $this->draftFor($owner, ['name' => 'Mon travail']);
        $other = $this->draftFor(User::factory()->create(), ['name' => 'Travail confidentiel autre compte']);
        $group = Group::factory()->create(['owner_id' => $owner->id]);
        $published = $this->draftFor($owner, ['name' => 'Déjà publié'], [
            'status' => 'published', 'published_group_id' => $group->id,
        ]);
        $this->authenticateDraftOwner($owner, $base);

        $index = $this->getJson($base)->assertOk()->assertJsonCount(1, 'drafts');
        $index->assertSee($mine->id)->assertDontSee($other->id)->assertDontSee($other->data['name'])
            ->assertDontSee($published->id);
        $this->getJson("{$base}/{$mine->id}")->assertOk()
            ->assertJsonPath('draft.id', $mine->id)
            ->assertJsonPath('draft.data', $mine->data);
    }

    public function test_dashboard_shows_only_owned_unpublished_drafts_with_safe_resume_links(): void
    {
        $owner = User::factory()->create();
        $mine = $this->draftFor($owner, ['name' => 'À reprendre']);
        $publishing = $this->draftFor($owner, ['name' => 'À réessayer'], ['status' => 'publishing']);
        $other = $this->draftFor(User::factory()->create(), ['name' => 'Brouillon autre compte']);

        $this->actingAs($owner)->get('/dashboard/subscriptions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Subscriptions')
                ->has('drafts', 2)
                ->where('drafts', function ($drafts) use ($mine, $publishing): bool {
                    $this->assertEqualsCanonicalizing([$mine->id, $publishing->id], $drafts->pluck('id')->all());
                    foreach ($drafts as $draft) {
                        $this->assertSame('/dashboard/groups/drafts/'.$draft['id'], $draft['url']);
                        $this->assertArrayNotHasKey('owner_id', $draft);
                        $this->assertArrayNotHasKey('credential_password', $draft);
                    }

                    return true;
                }))
            ->assertDontSee($other->id)->assertDontSee($other->data['name']);
        $this->assertNoDraftSideEffects();
    }

    public function test_publication_destination_opens_owned_groups_and_ignores_unknown_tabs(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['' => 'joined', '?tab=owned' => 'owned', '?tab=unknown' => 'joined'] as $query => $expected) {
            $this->get('/dashboard/subscriptions'.$query)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/Subscriptions')
                    ->where('initialTab', $expected));
        }
    }

    public static function inaccessibleAccountOperations(): iterable
    {
        foreach (self::draftEndpoints() as $surface => [$base]) {
            foreach (['guest', 'unverified', 'suspended'] as $account) {
                foreach (['show', 'create', 'update', 'delete', 'publish', 'reopen'] as $operation) {
                    yield "{$surface}: {$account} {$operation}" => [$base, $account, $operation];
                }
            }
        }
        foreach (['guest', 'unverified', 'suspended'] as $account) {
            yield "api: {$account} index" => ['/api/group-drafts', $account, 'index'];
            yield "web: {$account} create page" => ['/group-drafts', $account, 'page'];
        }
    }

    #[DataProvider('inaccessibleAccountOperations')]
    public function test_every_entry_point_rejects_ineligible_accounts(string $base, string $account, string $operation): void
    {
        $attributes = match ($account) {
            'unverified' => ['email_verified_at' => null],
            'suspended' => ['status' => 'suspended', 'suspended_until' => now()->addDay()],
            default => [],
        };
        $owner = $this->readyOwner($attributes);
        $draft = $this->draftFor($owner);
        if ($account !== 'guest') {
            $this->authenticateDraftOwner($owner, $base);
        }
        $show = str_starts_with($base, '/api/')
            ? "{$base}/{$draft->id}" : "/dashboard/groups/drafts/{$draft->id}";
        $response = match ($operation) {
            'index' => $this->getJson($base),
            'page' => $this->getJson('/dashboard/groups/create'),
            'show' => $this->getJson($show),
            'create' => $this->postJson($base, ['id' => (string) Str::uuid(), 'data' => ['name' => 'Refus']]),
            'update' => $this->putJson("{$base}/{$draft->id}", ['version' => 1, 'data' => ['name' => 'Refus']]),
            'delete' => $this->deleteJson("{$base}/{$draft->id}", ['version' => 1]),
            'publish' => $this->postJson("{$base}/{$draft->id}/publish", ['version' => 1, 'certify' => true]),
            'reopen' => $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 1]),
        };
        if ($account === 'suspended' && $base === '/group-drafts' && $response->status() === 302) {
            $response->assertRedirect('/login');
        } else {
            $allowedStatuses = match ($account) {
                'guest' => [401],
                'unverified' => [403, 409],
                'suspended' => [403],
            };
            $this->assertContains($response->status(), $allowedStatuses);
        }
        $this->assertDatabaseCount('group_drafts', 1);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_reopening_a_failed_publication_requires_ownership_and_current_version(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, ['name' => 'À corriger'], ['status' => 'publishing', 'version' => 3]);
        $this->authenticateDraftOwner($owner, $base);

        $this->postJson("{$base}/{$draft->id}/reopen", [])->assertUnprocessable()
            ->assertJsonValidationErrors('version');
        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 2])->assertConflict();
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertSame(3, $draft->fresh()->version);

        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 3])->assertOk()
            ->assertJsonPath('draft.status', 'draft')
            ->assertJsonPath('draft.version', 4)
            ->assertJsonPath('draft.data.name', 'À corriger');
        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 3])->assertConflict();
        $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 4])->assertConflict();
        $this->putJson("{$base}/{$draft->id}", ['version' => 4, 'data' => ['name' => 'Corrigé']])
            ->assertOk()->assertJsonPath('draft.version', 5);
        $this->assertNull($draft->fresh()->published_group_id);
        $this->assertNoDraftSideEffects();
    }

    #[DataProvider('draftEndpoints')]
    public function test_another_user_cannot_reopen_a_publishing_draft(string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, attributes: ['status' => 'publishing']);
        $this->authenticateDraftOwner($this->readyOwner(), $base);

        $response = $this->postJson("{$base}/{$draft->id}/reopen", ['version' => 1]);
        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('publishing', $draft->fresh()->status);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertNoDraftSideEffects();
    }

    public function test_rejected_draft_credentials_are_not_flashed_by_web_form_validation(): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Sans secret']);
        $this->actingAs($owner);
        $secrets = [
            'credential_email' => 'draft-form-secret@example.test',
            'credential_password' => 'draft-form-password-946',
            'credential_notes' => 'draft-form-notes-946',
        ];

        $response = $this->from("/dashboard/groups/drafts/{$draft->id}")
            ->put("/group-drafts/{$draft->id}", ['version' => 1, 'data' => ['name' => 'Sans secret', ...$secrets]]);
        $this->assertContains($response->status(), [302, 422]);
        $this->assertSecretsAbsent($draft, $secrets, $response);
        $this->assertSame(['name' => 'Sans secret'], $draft->fresh()->data);
        $this->assertNoDraftSideEffects();
    }

    public static function rejectedNestedSecretOperations(): array
    {
        return ['create' => ['POST'], 'update' => ['PUT']];
    }

    #[DataProvider('rejectedNestedSecretOperations')]
    public function test_rejected_unknown_nested_data_cannot_store_a_secret_in_flash_input(string $method): void
    {
        $owner = User::factory()->create();
        $draft = $this->draftFor($owner, ['name' => 'Sans secret imbriqué']);
        $this->actingAs($owner);
        $secret = 'rejected-nested-password-947';
        $payload = [
            'data' => ['name' => 'Sans secret imbriqué', 'settings' => ['credential_password' => $secret]],
        ];
        $payload += $method === 'POST' ? ['id' => (string) Str::uuid()] : ['version' => 1];
        $url = $method === 'POST' ? '/group-drafts' : "/group-drafts/{$draft->id}";

        $response = $this->from("/dashboard/groups/drafts/{$draft->id}")
            ->call($method, $url, $payload);
        $this->assertContains($response->status(), [302, 422]);
        $this->assertSecretsAbsent($draft, [$secret], $response);
        $this->assertDatabaseCount('group_drafts', 1);
        $this->assertSame(['name' => 'Sans secret imbriqué'], $draft->fresh()->data);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertNoDraftSideEffects();
    }

    public function test_drafts_never_appear_on_public_surfaces_or_in_the_generated_sitemap(): void
    {
        $subscription = Subscription::factory()->create();
        $draft = $this->draftFor(User::factory()->create(), $this->validDraftData($subscription));
        $publicGroup = Group::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'Groupe public existant',
            'visibility' => 'public',
        ]);

        foreach (['/', '/services', "/groups/service/{$subscription->slug}", '/api/groups'] as $url) {
            $this->get($url)->assertOk()->assertDontSee($draft->id)
                ->assertDontSee($draft->data['name'])->assertDontSee($draft->data['description']);
        }
        $this->getJson('/api/groups')->assertOk()->assertJsonFragment(['id' => $publicGroup->id]);

        // The command writes to a private temporary public directory, never the site's sitemap.
        $directory = sys_get_temp_dir().'/equitab-draft-sitemap-'.Str::uuid();
        File::makeDirectory($directory);
        $previousPublicPath = public_path();
        try {
            $this->app->usePublicPath($directory);
            $this->artisan('equitab:generate-sitemap')->assertExitCode(0);
            $xml = File::get($directory.'/sitemap.xml');
            $this->assertStringContainsString('/groups/service/'.$subscription->slug, $xml);
            $this->assertStringNotContainsString($draft->id, $xml);
            $this->assertStringNotContainsString('group-drafts', $xml);
            $this->assertStringNotContainsString($draft->data['name'], $xml);
        } finally {
            $this->app->usePublicPath($previousPublicPath);
            File::deleteDirectory($directory);
        }
        $this->assertDatabaseCount('groups', 1);
    }

    public static function existingGroupRoutes(): iterable
    {
        foreach (['owner', 'other'] as $actor) {
            foreach (['', '/proration', '/credentials', '/messages', '/chat-members'] as $suffix) {
                yield "{$actor}: GET {$suffix}" => [$actor, 'GET', $suffix];
            }
            foreach (['/join', '/subscribe', '/pay', '/messages'] as $suffix) {
                yield "{$actor}: POST {$suffix}" => [$actor, 'POST', $suffix];
            }
        }
    }

    #[DataProvider('existingGroupRoutes')]
    public function test_draft_uuid_cannot_be_used_as_a_published_group_identifier(string $actor, string $method, string $suffix): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner);
        $this->authenticateDraftOwner($actor === 'owner' ? $owner : $this->readyOwner(), '/api/group-drafts');

        $this->json($method, "/api/groups/{$draft->id}{$suffix}", [
            'payment_method_id' => 'pm_draft_test',
            'content' => 'Tentative refusée',
        ])->assertNotFound();
        $this->get("/invite/{$draft->id}")->assertNotFound();
        $this->assertNoDraftSideEffects();
    }
}
