<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingTestCase;

class GroupInvitationTest extends BillingTestCase
{
    private function group(array $data = []): Group
    {
        return Group::factory()->private()->withCredentials()->create(['invite_token' => 'synthetic-invitation', ...$data]);
    }

    public function test_guest_is_guided_to_authentication_without_exposing_service_credentials(): void
    {
        $group = $this->group();
        $this->get('/invite/synthetic-invitation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('InvitePage')->where('accessState', 'guest')
            ->where('continueUrl', '/invite/synthetic-invitation/continue')
            ->missing('group.credential_email')->missing('group.credential_password'))
            ->assertSessionMissing('url.intended')->assertDontSee($group->credential_password);
        $this->get('/invite/synthetic-invitation/continue?auth=login')->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', '/invite/synthetic-invitation/continue');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('group_members', 0);
    }

    public function test_verified_login_returns_to_the_validated_invitation(): void
    {
        $this->group();
        $user = User::factory()->create();
        $this->get('/invite/synthetic-invitation/continue?auth=login')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/invite/synthetic-invitation/continue');
        $this->get('/invite/synthetic-invitation/continue')->assertRedirect(route('invite.show', 'synthetic-invitation'));
        $this->get('/invite/synthetic-invitation')->assertOk()->assertInertia(fn (Assert $page) => $page->where('accessState', 'checkout'));
    }

    public function test_registration_preserves_the_invitation_through_signed_email_verification(): void
    {
        $this->group();
        $this->get('/invite/synthetic-invitation/continue?auth=register')->assertRedirect(route('register'));
        $this->post('/register', [
            'name' => 'Synthetic invitee', 'email' => 'invited@example.test',
            'password' => 'SyntheticPassword123!', 'password_confirmation' => 'SyntheticPassword123!',
        ])->assertRedirect('/invite/synthetic-invitation/continue');
        $user = User::where('email', 'invited@example.test')->sole();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->get('/invite/synthetic-invitation/continue')->assertRedirect(route('verification.notice'))
            ->assertSessionHas('url.intended', '/invite/synthetic-invitation/continue');
        $this->get('/invite/synthetic-invitation')->assertInertia(fn (Assert $page) => $page->where('accessState', 'verify_email'));
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(20), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect('/invite/synthetic-invitation/continue');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/invite/synthetic-invitation/continue')->assertRedirect(route('invite.show', 'synthetic-invitation'));
        $this->get('/invite/synthetic-invitation')->assertInertia(fn (Assert $page) => $page->where('accessState', 'checkout'));
        $this->assertDatabaseCount('group_members', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unverified_existing_account_is_directed_to_verification_and_cannot_pay(): void
    {
        $group = $this->group();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/invite/synthetic-invitation/continue')->assertRedirect(route('verification.notice'));
        $this->get('/invite/synthetic-invitation')->assertInertia(fn (Assert $page) => $page->where('accessState', 'verify_email'));
        $this->postJson('/api/groups/'.$group->id.'/subscribe', [
            'payment_method_id' => 'pm_synthetic', 'invite_token' => 'synthetic-invitation',
        ])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public static function restrictedModes(): array
    {
        return ['private' => ['private'], 'legacy' => ['invite_only']];
    }

    #[DataProvider('restrictedModes')]
    public function test_full_groups_remain_readable_without_allowing_an_extra_member(string $mode): void
    {
        $group = $this->group(['status' => 'full', 'current_members' => 6, 'max_members' => 6]);
        DB::table('groups')->where('id', $group->id)->update(['visibility' => $mode]);
        $this->get('/invite/synthetic-invitation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('accessState', 'full')->where('group.spotsAvailable', 0));
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/groups/'.$group->id.'/subscribe', [
                'payment_method_id' => 'pm_synthetic', 'invite_token' => 'synthetic-invitation',
            ])->assertConflict();
        $this->assertDatabaseCount('group_members', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function members(): array
    {
        return [
            'owner' => ['owner', 'owner'], 'active' => ['active', 'member'],
            'reserved' => ['pending_payment', 'checkout'], 'left' => ['left', 'unavailable'],
            'kicked' => ['kicked', 'unavailable'], 'cancelled' => ['cancelled', 'unavailable'],
        ];
    }

    #[DataProvider('members')]
    public function test_existing_members_have_the_correct_action_and_no_duplicate_join(string $status, string $expected): void
    {
        $group = $this->group();
        $user = $status === 'owner' ? $group->owner : User::factory()->create();
        if ($status !== 'owner') {
            GroupMember::factory()->for($group)->for($user)->create([
                'status' => $status === 'cancelled' ? 'pending_payment' : $status,
                'cancellation_requested_at' => $status === 'cancelled' ? now() : null,
            ]);
        }
        $this->actingAs($user)->get('/invite/synthetic-invitation')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('accessState', $expected));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_reserved_member_can_resume_payment_when_the_last_seat_is_reserved(): void
    {
        $group = $this->group(['status' => 'full', 'current_members' => 2, 'max_members' => 2]);
        $user = User::factory()->create();
        GroupMember::factory()->for($group)->for($user)->create(['status' => 'pending_payment']);
        $this->actingAs($user)->get('/invite/synthetic-invitation')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('accessState', 'checkout')->where('group.spotsAvailable', 0));
    }

    public function test_invalid_or_closed_invitations_cannot_replace_the_authentication_return_destination(): void
    {
        $group = $this->group();
        $this->get('/invite/wrong-token/continue?auth=login')->assertNotFound()->assertSessionMissing('url.intended');
        $this->getJson('/invite/synthetic-invitation/continue?auth=https://outside.example.test')->assertUnprocessable()
            ->assertSessionMissing('url.intended');
        $this->get('/invite/synthetic-invitation/continue?auth=login&redirect=https://outside.example.test')
            ->assertRedirect(route('login'))->assertSessionHas('url.intended', '/invite/synthetic-invitation/continue');
        $group->update(['status' => 'closed']);
        $this->get('/invite/synthetic-invitation')->assertNotFound();
        $this->get('/invite/synthetic-invitation/continue')->assertNotFound();
    }
}
