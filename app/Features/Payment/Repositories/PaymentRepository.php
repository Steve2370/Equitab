<?php

namespace App\Features\Payment\Repositories;

use App\Models\Payment;
use App\Features\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function findById(int $id): ?Payment
    {
        return Payment::find($id);
    }

    public function findByUuid(string $uuid): ?Payment
    {
        return Payment::where('uuid', $uuid)->first();
    }

    public function findPendingForUser(int $userId): Collection
    {
        return Payment::where('user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('due_date')
            ->get();
    }

    public function findOverdue(): Collection
    {
        return Payment::where('status', 'pending')
            ->where('due_date', '<', now())
            ->get();
    }

    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function markAsPaid(Payment $payment, int $transactionId): Payment
    {
        $payment->update([
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'paid_at' => now(),
        ]);

        return $payment->fresh();
    }
}
