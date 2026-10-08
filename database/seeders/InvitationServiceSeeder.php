<?php

namespace Database\Seeders;

use App\Features\Subscription\Services\InvitationServiceCatalogue;
use Illuminate\Database\Seeder;

class InvitationServiceSeeder extends Seeder
{
    public function run(InvitationServiceCatalogue $catalogue): void
    {
        $catalogue->prepare(apply: true);
    }
}
