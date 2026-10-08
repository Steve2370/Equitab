<?php

namespace App\Features\Group\Controllers;

use App\Features\Group\Services\GroupInvitationPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GroupInvitationController extends Controller
{
    public function __construct(private readonly GroupInvitationPage $page) {}

    public function show(Request $request, string $token): Response
    {
        return Inertia::render('InvitePage', $this->page->props($this->page->find($token), $request->user()));
    }

    public function continue(Request $request, string $token): RedirectResponse
    {
        $group = $this->page->find($token);
        if (! $group->subscription?->is_active && (! $request->user() || ! $request->user()->hasVerifiedEmail())) {
            return redirect()->route('invite.show', $token);
        }
        $data = $request->validate(['auth' => ['nullable', 'in:login,register']]);
        if (! $request->user() || ! $request->user()->hasVerifiedEmail()) {
            // Only a validated invitation route can become the return destination.
            // Re-entering here after login/registration preserves the email-verification step.
            $request->session()->put('url.intended', route('invite.continue', $token, absolute: false));

            return redirect()->route($request->user() ? 'verification.notice' : ($data['auth'] ?? 'login'));
        }

        return redirect()->route('invite.show', $token);
    }
}
