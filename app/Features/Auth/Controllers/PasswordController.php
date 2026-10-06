<?php

namespace App\Features\Auth\Controllers;

use App\Features\Auth\Services\AccountAccessRevoker;
use App\Features\Auth\Services\AccountSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = app(AccountAccessRevoker::class)->replacePassword($request->user(), $validated['password']);
        Auth::guard('web')->setUser($user);
        $request->session()->regenerate();
        app(AccountSession::class)->remember($request->session(), $user);

        return back();
    }
}
