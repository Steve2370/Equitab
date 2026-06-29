<?php

namespace App\Console\Commands;

use App\Mail\RenewalReminder;
use App\Models\GroupMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendRenewalReminders extends Command
{
    protected $signature = 'equitab:renewal-reminders';
    protected $description = 'Envoyer les rappels de renouvellement 3 jours avant la date de facturation';

    public function handle(): void
    {
        $reminderDate = now()->addDays(3)->startOfDay();

        $members = GroupMember::with(['user', 'group.subscription'])
            ->where('status', 'active')
            ->whereDate('next_payment_at', $reminderDate)
            ->whereHas('user', fn($q) => $q->where('notif_renewal_reminder', true))
            ->get();

        foreach ($members as $member) {
            Mail::to($member->user->email)->send(new RenewalReminder($member));
            $this->info("Rappel envoyé à {$member->user->email}");
        }

        $this->info("Total : {$members->count()} rappels envoyés.");
    }
}
