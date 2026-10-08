<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Jobs\RecalculateGroupPrices;
use App\Mail\PriceChanged;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingTestCase;

class RecalculateFullGroupPricesTest extends BillingTestCase
{
    public static function currencies(): array
    {
        return [['CAD'], ['EUR']];
    }

    private function group(string $currency, string $status): array
    {
        $group = Group::factory()->create([
            'currency' => $currency, 'total_price' => 3000,
            'max_members' => 3, 'current_members' => 3, 'status' => $status,
        ]);
        GroupMember::factory()->owner()->create(['group_id' => $group->id, 'user_id' => $group->owner_id]);
        $members = [];
        foreach (['first', 'last'] as $name) {
            $members[] = GroupMember::factory()->create([
                'group_id' => $group->id, 'share_amount' => 1500,
                'stripe_subscription_item_id' => 'si_'.$name,
                'stripe_subscription_id' => 'sub_'.$name, 'subscription_status' => 'active',
            ]);
        }

        return [$group, $members];
    }

    #[DataProvider('currencies')]
    public function test_last_joining_member_does_not_freeze_other_members_at_the_old_price(string $currency): void
    {
        // Existing commitments must be maintained even when new EUR sales are off.
        config(['payments.eur_enabled' => false, 'payments.eurozone_connect_enabled' => false]);
        [$group, $members] = $this->group($currency, 'full');
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('createMonthlyPrice')->once()->withArgs(function (Group $actual, int $amount) use ($group, $currency) {
            return $actual->id === $group->id && $actual->currency === $currency && $amount === 1000;
        })->andReturn('price_recalculated');
        foreach ($members as $member) {
            $gateway->shouldReceive('updateSubscriptionItemPrice')->once()->with($member->stripe_subscription_item_id, 'price_recalculated');
        }

        (new RecalculateGroupPrices)->handle($gateway);

        foreach ($members as $member) {
            $this->assertSame(1000, $member->fresh()->share_amount);
            Mail::assertSent(PriceChanged::class, fn ($mail) => $mail->member->id === $member->id
                && $mail->oldPrice === 1500 && $mail->newPrice === 1000 && $mail->member->group->currency === $currency);
        }
        $this->assertSame('full', $group->fresh()->status);
        $this->assertSame(3, $group->fresh()->current_members);
        $this->assertDatabaseCount('payments', 0);
    }

    #[DataProvider('currencies')]
    public function test_closed_groups_remain_excluded_without_gateway_calls_or_emails(string $currency): void
    {
        [, $members] = $this->group($currency, 'closed');
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldNotReceive('createMonthlyPrice', 'updateSubscriptionItemPrice');
        (new RecalculateGroupPrices)->handle($gateway);
        foreach ($members as $member) {
            $this->assertSame(1500, $member->fresh()->share_amount);
        }
        Mail::assertNothingSent();
    }
}
