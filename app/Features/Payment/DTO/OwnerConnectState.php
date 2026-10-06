<?php

namespace App\Features\Payment\DTO;

final readonly class OwnerConnectState
{
    public function __construct(
        public string $id,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public bool $pendingVerification = false,
        public ?string $disabledReason = null,
        public bool $pastDue = false,
    ) {}

    public function status(): string
    {
        return match (true) {
            $this->pastDue,
            $this->disabledReason !== null && $this->disabledReason !== 'requirements.pending_verification' => 'restricted',
            $this->pendingVerification,
            $this->disabledReason === 'requirements.pending_verification' => 'pending',
            $this->chargesEnabled && $this->payoutsEnabled => 'active',
            $this->detailsSubmitted => 'pending',
            default => 'restricted',
        };
    }
}
