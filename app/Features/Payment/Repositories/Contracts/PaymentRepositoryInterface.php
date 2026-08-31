<?php

namespace App\Features\Payment\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

interface PaymentRepositoryInterface
{
    public function findById(int $id): ?Payment;
    public function findByUuid(string $uuid): ?Payment;
    public function findPendingForUser(int $userId): Collection;
    public function findOverdue(): Collection;
    public function create(array $data): Payment;
    public function markAsPaid(Payment $payment, int $transactionId): Payment;
}
