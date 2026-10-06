<?php

namespace App\Features\Group\Contracts;

interface GroupProductGateway
{
    /** Return the same product for every retry, even after a lost response. */
    public function ensureProduct(string $draftId, string $name, int $ownerId, int $version): string;
}
