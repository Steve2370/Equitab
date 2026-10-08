<?php

namespace Tests\Postgres;

use App\Features\Subscription\Services\InvitationServiceCatalogue;
use Illuminate\Support\Facades\DB;
use Tests\Support\InvitationCatalogueMigrationChecks;

class InvitationCatalogueMigrationTest extends PostgresTestCase
{
    use InvitationCatalogueMigrationChecks;

    public function test_targeted_preparation_is_atomic_and_idempotent_on_postgresql(): void
    {
        $catalogue = app(InvitationServiceCatalogue::class);
        $catalogue->prepare(apply: true);
        $before = DB::table('subscriptions')->orderBy('id')->get()->all();
        $catalogue->prepare(apply: true);
        $this->assertEquals($before, DB::table('subscriptions')->orderBy('id')->get()->all());
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('subscription_categories', 2);
        $this->assertDatabaseCount('groups', 0);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }
}
