<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuth2User;
use Tests\Feature\Auth\AccountSecurityTestCase;

class WalletRetirementTest extends AccountSecurityTestCase
{
    public function test_retired_endpoints_are_absent_and_historical_wallets_and_transactions_are_unchanged(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance' => 1450, 'currency' => 'CAD']);
        $transaction = Transaction::create([
            'wallet_id' => $wallet->id, 'user_id' => $user->id, 'type' => 'deposit',
            'amount' => 1450, 'balance_after' => 1450, 'currency' => 'CAD',
            'description' => 'Historical synthetic record', 'status' => 'completed',
        ]);
        $beforeWallet = $wallet->fresh()->getAttributes();
        $beforeTransaction = $transaction->fresh()->getAttributes();
        $token = $user->createToken('historical-user')->plainTextToken;

        foreach ([[], ['Authorization' => 'Bearer '.$token]] as $headers) {
            Auth::forgetGuards();
            $this->getJson('/api/wallet', $headers)->assertNotFound();
            $this->getJson('/api/wallet/transactions', $headers)->assertNotFound();
            $this->postJson('/api/wallet/credit', ['amount' => 1000000], $headers)->assertNotFound();
        }

        $this->assertSame($beforeWallet, $wallet->fresh()->getAttributes());
        $this->assertSame($beforeTransaction, $transaction->fresh()->getAttributes());
        $this->assertSame($wallet->id, $user->wallet->id);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_api_registration_and_login_do_not_create_or_serialize_wallets(): void
    {
        $data = [
            'name' => 'No Wallet', 'email' => 'no-wallet@example.test',
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
        ];
        $this->postJson('/api/register', $data)->assertCreated()->assertJsonMissingPath('user.wallet');
        $this->postJson('/api/login', $data)->assertOk()->assertJsonMissingPath('user.wallet');
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertNull($user->wallet);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_legacy_wallet_is_not_serialized_at_login_but_remains_preserved(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'balance' => 3400, 'currency' => 'CAD']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonMissingPath('user.wallet');

        $this->assertSame(3400, $wallet->fresh()->balance);
        $this->assertDatabaseCount('wallets', 1);
    }

    public function test_web_and_google_registration_do_not_create_wallets(): void
    {
        $this->post('/register', [
            'name' => 'No Wallet Web', 'email' => 'no-wallet-web@example.test',
            'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password',
        ])->assertRedirect('/dashboard');
        $this->post('/logout');
        $identity = (new OAuth2User)->setRaw(['email_verified' => true])->map([
            'id' => 'google-no-wallet', 'email' => 'no-wallet-google@example.test',
            'name' => 'No Wallet Google', 'nickname' => null, 'avatar' => null,
        ]);
        Socialite::shouldReceive('driver->user')->once()->andReturn($identity);

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
}
