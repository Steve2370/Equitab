<?php

namespace App\Features\Group\Services;

use App\Models\Group;
use Illuminate\Validation\ValidationException;

/** Syntax/provider validation only: never claims the invitation has been accepted. */
final class ServiceInvitationUrl
{
    private const HOSTS = [
        'dropbox-family' => ['www.dropbox.com', 'dropbox.com'],
        'nordpass-family' => ['my.nordaccount.com', 'nordaccount.com', 'app.nordpass.com'],
    ];

    public function valid(Group $group, mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS[$group->subscription?->slug] ?? [], true)
            && (($parts['path'] ?? '/') !== '/' || filled($parts['query'] ?? null) || filled($parts['fragment'] ?? null));
    }

    public function validate(Group $group, mixed $url): string
    {
        if (! $this->valid($group, $url)) {
            throw ValidationException::withMessages(['invitation_url' => 'Utilisez le lien HTTPS d’invitation du fournisseur de ce service.']);
        }

        return $url;
    }
}
