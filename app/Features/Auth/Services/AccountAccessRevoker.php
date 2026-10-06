<?php

namespace App\Features\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountAccessRevoker
{
    /** Rotate credentials and revoke every previous session and API token atomically. */
    public function replacePassword(User $user, ?string $password): User
    {
        return DB::transaction(function () use ($user, $password): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $locked->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'auth_version' => $locked->auth_version + 1,
            ])->save();
            $locked->tokens()->delete();

            return $locked;
        });
    }
}
