<?php

namespace App\Features\Payment\DTO;

use App\Features\Payment\Services\OwnerOnboardingException;

final readonly class OwnerIdentityState
{
    public function __construct(
        public string $id,
        public string $stripeStatus,
        public ?string $url = null,
    ) {}

    public function status(): string
    {
        return match ($this->stripeStatus) {
            'verified' => 'verified',
            'processing' => 'pending',
            'requires_input', 'canceled' => 'unverified',
            default => throw new OwnerOnboardingException,
        };
    }
}
