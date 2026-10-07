<?php

namespace App\Features\Group\Services;

final class GroupVisibility
{
    /** Keep the old API value readable while exposing only two choices. */
    public const ACCEPTED = ['public', 'private', 'invite_only'];

    public const RESTRICTED = ['private', 'invite_only'];

    public static function normalize(?string $value): ?string
    {
        return $value === 'invite_only' ? 'private' : $value;
    }

    public static function normalizeData(array $data): array
    {
        if (array_key_exists('visibility', $data)) {
            $data['visibility'] = self::normalize($data['visibility']);
        }

        return $data;
    }
}
