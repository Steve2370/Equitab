<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'subscription_id' => Subscription::factory(),
            'owner_id' => User::factory(),
            'name' => fake()->words(2, true) . ' Group',
            'description' => null,
            'max_members' => 6,
            'current_members' => 1,
            'price_per_member' => null,
            'total_price' => 1999,
            'split_type' => 'equal',
            'status' => 'open',
            'visibility' => 'public',
            'tier' => 'standard',
            'renewal_date' => now()->addMonth()->toDateString(),
            'auto_renew' => true,
            'settings' => null,
            'credential_email' => null,
            'credential_password' => null,
            'credential_notes' => null,
            'invite_token' => null,
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => 'private']);
    }

    public function inviteOnly(): static
    {
        return $this->state(fn () => ['visibility' => 'invite_only']);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'full',
            'current_members' => $attributes['max_members'] ?? 6,
        ]);
    }

    public function withCredentials(): static
    {
        return $this->state(fn () => [
            'credential_email' => 'compte-partage@example.com',
            'credential_password' => 'super-secret-password',
            'credential_notes' => 'Profil 3, code parental 1234',
        ]);
    }
}
