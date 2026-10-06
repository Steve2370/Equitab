<?php

namespace App\Features\Payment\Services;

use App\Mail\NewMemberJoined;
use App\Mail\PaymentConfirmed;
use App\Models\GroupMember;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class PaymentConfirmationNotifier
{
    public function sendOnce(Payment $payment, GroupMember $member): void
    {
        // Best-effort delivery: replaying confirmation must not spam recipients.
        if (! Payment::whereKey($payment->id)->whereNull('confirmation_notified_at')->update(['confirmation_notified_at' => now()])) {
            return;
        }
        try {
            Mail::to($member->user->email)->send(new PaymentConfirmed($payment, $member));
            Mail::to($member->group->owner->email)->send(new NewMemberJoined($member->group->load('subscription', 'owner'), $member->user, $member->share_amount));
        } catch (Throwable) {
            Log::warning('Notification de confirmation non délivrée.', ['payment_id' => $payment->id]);
        }
    }
}
