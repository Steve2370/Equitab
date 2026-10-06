<?php

namespace App\Features\Auth\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

class AccountSession
{
    public const KEY = 'auth.account_version';

    public function remember(Session $session, User $user): void
    {
        $session->put(self::KEY, ['id' => $user->getKey(), 'version' => (int) $user->auth_version]);
    }

    public function isCurrent(Session $session, User $user): bool
    {
        $state = $session->get(self::KEY);

        // Existing sessions remain valid only until the first explicit recovery.
        if ($state === null && (int) $user->auth_version === 0) {
            $this->remember($session, $user);

            return true;
        }

        return is_array($state)
            && ($state['id'] ?? null) === $user->getKey()
            && ($state['version'] ?? null) === (int) $user->auth_version;
    }
}
