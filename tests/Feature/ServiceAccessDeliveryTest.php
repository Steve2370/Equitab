<?php

namespace Tests\Feature;

use App\Features\Group\Services\ServiceAccessDelivery;
use App\Features\Group\Services\ServiceAccessPublication;
use App\Features\Payment\Contracts\BillingReadGatewayInterface;
use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\BillingReconciliationService;
use App\Features\Payment\Services\BillingUnavailable;
use App\Features\Payment\Services\PaymentService;
use App\Jobs\CheckCredentialsProvided;
use App\Models\Group;
use App\Models\GroupDraft;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingTestCase;

class ServiceAccessDeliveryTest extends BillingTestCase
{
    private function fixture(string $slug = 'dropbox-family', string $status = 'active'): array
    {
        $subscription = Subscription::where('slug', $slug)->first()
            ?? Subscription::factory()->create(['slug' => $slug, 'access_mode' => 'invitation']);
        $group = Group::factory()->for($subscription)->create();
        $member = GroupMember::factory()->for($group)->create(['status' => $status]);

        return [$group, $member];
    }

    private function storeUrl(Group $group, GroupMember $member): string
    {
        return '/api/groups/'.$group->id.'/members/'.$member->id.'/service-access';
    }

    private function readUrl(Group $group): string
    {
        return '/api/groups/'.$group->id.'/service-access';
    }

    public static function selectedInvitationLinks(): array
    {
        return [
            'Dropbox' => ['dropbox-family', 'https://www.dropbox.com/family/join?token=synthetic-private-link'],
            'NordPass' => ['nordpass-family', 'https://my.nordaccount.com/invite?token=synthetic-private-link'],
        ];
    }

    #[DataProvider('selectedInvitationLinks')]
    public function test_invitation_is_encrypted_recipient_scoped_and_hidden_until_payment_is_verified(string $slug, string $url): void
    {
        [$group, $member] = $this->fixture($slug, status: 'pending_payment');
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), ['invitation_url' => $url])->assertOk();
        $raw = DB::table('group_members')->where('id', $member->id)->value('service_invitation_url');
        $this->assertStringNotContainsString('synthetic-private-link', $raw);
        $this->assertArrayNotHasKey('service_invitation_url', $member->fresh()->toArray());
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertOk()
            ->assertJsonPath('status', 'payment_pending')->assertJsonPath('invitation', null)->assertDontSee('synthetic-private-link');
        $member->update(['status' => 'active']);
        $this->getJson($this->readUrl($group))->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonPath('invitation.url', $url)->assertHeader('Cache-Control', 'no-store, private');
        $peer = GroupMember::factory()->for($group)->create();
        $this->actingAs($peer->user)->getJson($this->readUrl($group))->assertOk()
            ->assertJsonPath('status', 'awaiting_owner')->assertDontSee('synthetic-private-link');
        $this->actingAs(User::factory()->create())->getJson($this->readUrl($group))->assertNotFound();
        $this->getJson('/api/groups/'.$group->id)->assertDontSee('synthetic-private-link');
    }

    public static function invalidUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'javascript:alert(1)', 'http://www.dropbox.com/join', 'https://www.dropbox.com.evil.test/join',
            'https://www.dropbox.com@evil.test/join', 'https://evil.test@www.dropbox.com/join',
            'https://www.dropbox.com:444/join', 'https://127.0.0.1/join', 'https://www.dropbox.com',
            'https://www.dropbox.com\\@evil.test/join', "https://www.dropbox.com/join\nsecret", [],
        ]);
    }

    #[DataProvider('invalidUrls')]
    public function test_untrusted_invitation_destinations_are_rejected(mixed $url): void
    {
        [$group, $member] = $this->fixture();
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), ['invitation_url' => $url])
            ->assertUnprocessable()->assertJsonValidationErrors('invitation_url');
        $this->assertNull($member->fresh()->service_invitation_provided_at);
    }

    public function test_member_cannot_assign_or_read_another_groups_invitation(): void
    {
        [$group, $member] = $this->fixture();
        [, $other] = $this->fixture('nordpass-family');
        $body = ['invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic'];
        $this->actingAs($member->user)->putJson($this->storeUrl($group, $member), $body)->assertForbidden();
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $other), $body)->assertNotFound();
        $this->assertNull($other->fresh()->service_invitation_url);
    }

    public function test_bitwarden_uses_provider_email_not_a_fictitious_shareable_family_link(): void
    {
        [$group, $member] = $this->fixture('bitwarden-families', 'pending_payment');
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), ['invitation_sent' => true])->assertConflict();
        $member->update(['status' => 'active']);
        $this->putJson($this->storeUrl($group, $member), [])->assertUnprocessable();
        $this->putJson($this->storeUrl($group, $member), ['invitation_sent' => true])->assertOk();
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertOk()
            ->assertJsonPath('status', 'ready')->assertJsonPath('invitation.channel', 'provider_email')
            ->assertJsonPath('invitation.url', null)->assertJsonPath('invitation.recipient_email', $member->user->email);
        $this->assertStringNotContainsString($member->user->email, DB::table('group_members')->where('id', $member->id)->value('service_invitation_email'));
    }

    public function test_no_master_password_is_accepted_at_delivery_or_publication(): void
    {
        [$group, $member] = $this->fixture('bitwarden-families');
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_sent' => true, 'credential_password' => 'synthetic-master-secret',
        ])->assertUnprocessable()->assertDontSee('synthetic-master-secret');
        $this->assertNull($member->fresh()->service_invitation_provided_at);
        $this->expectException(ValidationException::class);
        app(ServiceAccessPublication::class)->validate($group->subscription, ['credential_notes' => 'synthetic-secret']);
    }

    public function test_new_checks_need_both_credentials_but_legacy_checks_are_not_reinterpreted(): void
    {
        $group = Group::factory()->create(['credential_email' => 'synthetic@example.test']);
        $member = GroupMember::factory()->for($group)->create();
        $legacy = Payment::factory()->for($group)->for($member->user)->create();
        $current = Payment::factory()->for($group)->for($member->user)->create(['access_check_version' => 2]);
        $delivery = app(ServiceAccessDelivery::class);
        $this->assertTrue($delivery->providedFor($legacy->fresh(), $group));
        $this->assertFalse($delivery->providedFor($current, $group));
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertJsonPath('status', 'awaiting_owner');
        $this->actingAs($group->owner)->putJson($this->readUrl($group), [
            'credential_email' => 'synthetic@example.test', 'credential_password' => 'synthetic-password',
        ])->assertOk();
        $this->assertTrue($delivery->providedFor($current, $group->fresh()));
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertJsonPath('status', 'ready');
    }

    public function test_member_silence_never_refunds_a_delivered_invitation(): void
    {
        [$group, $member] = $this->fixture();
        $payment = Payment::factory()->for($group)->for($member->user)->create(['access_check_version' => 2]);
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=synth',
        ])->assertOk();
        // No member GET, click, acknowledgement, or acceptance is simulated.
        $this->travel(49)->hours();
        $this->mock(PaymentGatewayInterface::class)->shouldNotReceive('refundPayment');
        (new CheckCredentialsProvided($payment->id, $group->id, $member->user_id))->handle(app(BillingReconciliationService::class));
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_refund_attempts', 0);
    }

    public function test_missing_invitation_still_refunds_and_cannot_be_delivered_after_cancellation(): void
    {
        [$group, $member] = $this->fixture();
        $payment = Payment::factory()->for($group)->for($member->user)->create(['access_check_version' => 2]);
        $this->mock(PaymentGatewayInterface::class)->shouldReceive('refundPayment')->once()
            ->with($payment->stripe_payment_intent_id, null, \Mockery::type('string'))
            ->andReturn(['refund_id' => 're_synthetic_missing_access', 'status' => 'succeeded']);
        (new CheckCredentialsProvided($payment->id, $group->id, $member->user_id))->handle(app(BillingReconciliationService::class));
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertNotNull($member->fresh()->cancellation_requested_at);
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=synth',
        ])->assertConflict();
    }

    public function test_using_an_invitation_then_leaving_does_not_erase_delivery_for_the_48_hour_check(): void
    {
        [$group, $member] = $this->fixture();
        $payment = Payment::factory()->for($group)->for($member->user)->create(['access_check_version' => 2]);
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-already-delivered',
        ])->assertOk();
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertJsonPath('status', 'ready');
        $member->update(['status' => 'left', 'cancellation_requested_at' => now()]);
        $this->actingAs($group->owner)->postJson($this->storeUrl($group, $member).'/revoke', ['removed_at_provider' => true])->assertOk();
        $this->assertNull($member->fresh()->service_invitation_url);
        $this->assertNotNull($member->fresh()->service_invitation_provided_at);
        $this->travel(49)->hours();
        $this->mock(PaymentGatewayInterface::class)->shouldNotReceive('refundPayment');
        (new CheckCredentialsProvided($payment->id, $group->id, $member->user_id))->handle(app(BillingReconciliationService::class));
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_refund_attempts', 0);
    }

    public function test_expired_access_is_hidden_and_owner_can_record_actual_provider_removal(): void
    {
        [$group, $member] = $this->fixture();
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=expired-synthetic',
        ])->assertOk();
        $this->postJson($this->storeUrl($group, $member).'/revoke', ['removed_at_provider' => true])->assertConflict();
        $member->update(['status' => 'left', 'cancellation_requested_at' => now()]);
        $group->delete();
        $this->get('/dashboard/subscriptions')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('accessRevocations', 1)->where('accessRevocations.0.id', $group->id));
        $this->get('/dashboard/groups/'.$group->id.'/access')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('group.closed', true)->where('group.members.0.revoke_required', true));
        $this->postJson($this->storeUrl($group, $member).'/revoke', [])->assertUnprocessable();
        $this->postJson($this->storeUrl($group, $member).'/revoke', ['removed_at_provider' => true])->assertOk();
        $this->postJson($this->storeUrl($group, $member).'/revoke', ['removed_at_provider' => true])->assertOk();
        $this->assertNull($member->fresh()->service_invitation_url);
        $this->assertNotNull($member->fresh()->service_access_revoked_at);
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertNotFound()->assertDontSee('expired-synthetic');
    }

    public function test_refund_and_delivery_share_a_lock_instead_of_racing(): void
    {
        [$group, $member] = $this->fixture();
        $payment = Payment::factory()->for($group)->for($member->user)->create();
        $lock = Cache::store('database')->lock('billing:access-delivery:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(BillingUnavailable::class);
            (new CheckCredentialsProvided($payment->id, $group->id, $member->user_id))->handle(app(BillingReconciliationService::class));
        } finally {
            $lock->release();
            $this->assertDatabaseCount('payment_refund_attempts', 0);
        }
    }

    public function test_legacy_and_new_endpoints_deny_secrets_as_soon_as_a_refund_is_durable(): void
    {
        $group = Group::factory()->withCredentials()->create();
        $member = GroupMember::factory()->for($group)->create();
        $payment = Payment::factory()->for($group)->for($member->user)->create();
        $this->mock(PaymentGatewayInterface::class)->shouldNotReceive('refundPayment');
        $lock = Cache::store('database')->lock('billing:group:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            try {
                app(BillingReconciliationService::class)->refund($payment, 'synthetic_access_problem');
                $this->fail('Cancellation must remain retryable while its lock is held.');
            } catch (BillingUnavailable) {
                $this->assertDatabaseHas('payment_refund_attempts', ['payment_id' => $payment->id, 'status' => 'requested']);
            }
            $this->assertSame('active', $member->fresh()->status);
            $this->assertNull($member->fresh()->cancellation_requested_at);
            $this->actingAs($member->user)->getJson($this->readUrl($group))->assertOk()
                ->assertJsonPath('status', 'unavailable')->assertJsonPath('credentials', null);
            $this->getJson('/api/groups/'.$group->id.'/credentials')->assertForbidden()
                ->assertDontSee($group->credential_password);
        } finally {
            $lock->release();
        }
    }

    public function test_suspended_group_exposes_removal_but_never_new_delivery(): void
    {
        [$group, $member] = $this->fixture();
        $body = ['invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-suspension'];
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), $body)->assertOk();
        $group->update(['status' => 'suspended']);
        $this->get('/dashboard/groups/'.$group->id.'/access')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('group.closed', true)->where('group.members.0.revoke_required', true));
        $this->putJson($this->storeUrl($group, $member), $body)->assertConflict();
        $this->postJson($this->storeUrl($group, $member).'/revoke', ['removed_at_provider' => true])->assertOk();
        $this->assertNotNull($member->fresh()->service_access_revoked_at);
        $this->assertNull($member->fresh()->service_invitation_url);
    }

    public function test_busy_delivery_is_retryable_without_claiming_it_was_saved(): void
    {
        [$group, $member] = $this->fixture();
        $lock = Cache::store('database')->lock('billing:access-delivery:'.$group->id, 300);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
                'invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-busy',
            ])->assertStatus(503)->assertHeader('Retry-After', '3')->assertDontSee('synthetic-busy');
            $this->assertNull($member->fresh()->service_invitation_provided_at);
        } finally {
            $lock->release();
        }
    }

    public function test_stripe_server_proof_unlocks_access_without_a_member_acknowledgement(): void
    {
        $group = Group::factory()->withCredentials()->create();
        $member = GroupMember::factory()->for($group)->create([
            'status' => 'pending_payment', 'stripe_subscription_id' => 'sub_delivery', 'stripe_customer_id' => 'cus_delivery',
        ]);
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertJsonPath('status', 'payment_pending');
        $reader = $this->mock(BillingReadGatewayInterface::class);
        $reader->shouldReceive('retrieveSubscription')->once()->with('sub_delivery')->andReturn([
            'id' => 'sub_delivery', 'customer' => 'cus_delivery', 'status' => 'active', 'currency' => 'cad',
            'latest_invoice' => 'in_delivery', 'current_period_end' => now()->addMonth()->timestamp,
        ]);
        $reader->shouldReceive('retrieveInvoice')->once()->with('in_delivery')->andReturn([
            'id' => 'in_delivery', 'subscription' => 'sub_delivery', 'customer' => 'cus_delivery', 'currency' => 'cad',
            'status' => 'paid', 'amount_paid' => 500, 'amount_remaining' => 0, 'payment_intent' => 'pi_delivery',
            'period_start' => now()->timestamp, 'period_end' => now()->addMonth()->timestamp,
        ]);
        $reader->shouldReceive('retrievePaymentIntent')->once()->with('pi_delivery')->andReturn([
            'id' => 'pi_delivery', 'customer' => 'cus_delivery', 'currency' => 'cad', 'status' => 'succeeded',
            'amount_received' => 500, 'latest_charge' => ['refunded' => false, 'amount_refunded' => 0],
        ]);
        app(PaymentService::class)->synchronizeSubscription('sub_delivery');
        $this->getJson($this->readUrl($group))->assertJsonPath('status', 'ready')
            ->assertJsonPath('credentials.password', $group->credential_password);
        $this->assertDatabaseHas('payments', ['stripe_invoice_id' => 'in_delivery', 'access_check_version' => 2]);
        $this->get('/payment/success?group_id='.$group->id)->assertOk()
            ->assertDontSee($group->credential_password)->assertDontSee($group->credential_email)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('serviceAccess.status', 'ready')
                ->where('serviceAccess.credentials', null)->where('credentials', null));
    }

    public function test_publication_rejects_invitation_secrets_before_any_provider_call(): void
    {
        [$group] = $this->fixture('bitwarden-families');
        $draft = new GroupDraft;
        $draft->forceFill(['owner_id' => $group->owner_id, 'version' => 1, 'status' => 'draft',
            'data' => ['subscription_id' => $group->subscription_id]])->save();
        $this->actingAs($group->owner)->postJson('/api/group-drafts/'.$draft->id.'/publish', [
            'version' => 1, 'certify' => true, 'credential_password' => 'synthetic-never-store',
        ])->assertUnprocessable()->assertJsonValidationErrors('credential_password')->assertDontSee('synthetic-never-store');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertStringNotContainsString('synthetic-never-store', json_encode($draft->fresh()->data));
        $this->assertDatabaseCount('stripe_prices', 0);
    }

    public function test_a_report_of_invalid_access_opens_a_case_without_automatic_refund(): void
    {
        [$group, $member] = $this->fixture();
        $payment = Payment::factory()->for($group)->for($member->user)->create(['access_check_version' => 2]);
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-disputed',
        ])->assertOk();
        $this->actingAs($member->user)->postJson('/api/payments/'.$payment->id.'/dispute', [
            'reason' => 'no_access', 'description' => 'Invitation refusée par le fournisseur.',
        ])->assertCreated();
        $this->assertDatabaseHas('disputes', ['payment_id' => $payment->id, 'status' => 'open']);
        $this->assertDatabaseCount('payment_refund_attempts', 0);
        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_member_cannot_request_provider_removal_or_update_invitation_after_expiry(): void
    {
        [$group, $member] = $this->fixture();
        $this->actingAs($group->owner)->putJson($this->storeUrl($group, $member), [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=synthetic-expiry',
        ])->assertOk();
        $member->update(['current_period_end' => now()->subMinute()]);
        $this->actingAs($member->user)->getJson($this->readUrl($group))->assertOk()
            ->assertJsonPath('status', 'unavailable')->assertJsonPath('invitation', null);
        $this->postJson($this->storeUrl($group, $member).'/revoke', ['removed_at_provider' => true])->assertForbidden();
    }

    public static function blockedStates(): array
    {
        return [['unverified'], ['suspended'], ['banned'], ['left'], ['canceled'], ['closed']];
    }

    #[DataProvider('blockedStates')]
    public function test_ineligible_accounts_never_receive_invitation_secrets(string $state): void
    {
        [$group, $member] = $this->fixture();
        app(ServiceAccessDelivery::class)->deliver($group->owner, $group, $member, [
            'invitation_url' => 'https://www.dropbox.com/family/join?token=must-stay-secret',
        ]);
        match ($state) {
            'unverified' => $member->user->forceFill(['email_verified_at' => null])->save(),
            'suspended' => $member->user->update(['status' => 'suspended', 'suspended_until' => now()->addDay()]),
            'banned' => $member->user->update(['status' => 'banned']),
            'left' => $member->update(['status' => 'left']),
            'canceled' => $member->update(['cancellation_requested_at' => now()]),
            'closed' => $group->update(['status' => 'closed']),
        };
        $response = $this->actingAs($member->user->fresh())->getJson($this->readUrl($group))->assertDontSee('must-stay-secret');
        if ($state === 'unverified') {
            $response->assertConflict()->assertJsonPath('message', 'Veuillez confirmer votre adresse courriel.');
        } elseif (in_array($state, ['suspended', 'banned'], true)) {
            $response->assertForbidden();
        } else {
            $response->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonPath('invitation', null);
        }
    }

    public function test_cookie_write_without_csrf_token_is_rejected_and_never_records_invitation(): void
    {
        [$group, $member] = $this->fixture();
        $this->actingAs($group->owner);
        config(['sanctum.stateful' => ['localhost']]);
        $this->app->instance('env', 'production');
        try {
            $this->withHeader('Origin', 'http://localhost')->putJson($this->storeUrl($group, $member), [
                'invitation_url' => 'https://www.dropbox.com/family/join?token=csrf-fixture',
            ])->assertStatus(419);
            $this->assertNull($member->fresh()->service_invitation_url);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
