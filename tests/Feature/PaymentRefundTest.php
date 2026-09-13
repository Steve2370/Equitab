<?php

namespace Tests\Feature;

use App\Features\Payment\Contracts\PaymentGatewayInterface;
use App\Features\Payment\Services\PaymentService;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le finding critique P0 « remboursement non fiable » : les deux
 * implémentations dupliquées (CheckCredentialsProvided et
 * AdminController::resolveDispute) marquaient le paiement "refunded" sans
 * jamais vérifier le résultat réel renvoyé par Stripe, et n'annulaient
 * jamais l'abonnement Stripe du membre. PaymentService::refundPayment()
 * est désormais le seul point d'entrée : idempotent, il exige un
 * stripe_payment_intent_id, n'accepte qu'un statut Stripe succeeded/pending,
 * et annule l'abonnement + met à jour le membre/groupe.
 */
class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_requires_a_stripe_payment_intent_id(): void
    {
        $payment = Payment::factory()->withoutStripeIntent()->create(['status' => 'completed']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldNotReceive('refundPayment');
        });

        $this->expectException(Exception::class);

        app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');

        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_refund_is_rejected_when_stripe_reports_a_failed_status(): void
    {
        $payment = Payment::factory()->create(['status' => 'completed']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('refundPayment')->once()->andReturn([
                'refund_id' => 're_failed',
                'status' => 'failed',
                'amount' => 1999,
            ]);
        });

        $this->expectException(Exception::class);

        app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');

        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_refund_marks_payment_refunded_and_cancels_the_member_subscription(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $payment = Payment::factory()->for($group)->create(['status' => 'completed']);
        $member = GroupMember::factory()
            ->for($group)
            ->for($payment->user, 'user')
            ->withStripeSubscription('sub_to_cancel')
            ->create(['status' => 'active']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('refundPayment')->once()->andReturn([
                'refund_id' => 're_123',
                'status' => 'succeeded',
                'amount' => 1999,
            ]);
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_to_cancel');
        });

        $refunded = app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');

        $this->assertSame('refunded', $refunded->status);
        $this->assertSame('re_123', $refunded->stripe_refund_id);
        $this->assertNotNull($refunded->refunded_at);

        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame('canceled', $member->fresh()->subscription_status);

        // Le membre était actif : current_members doit être décrémenté.
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_refund_does_not_decrement_group_when_member_was_already_inactive(): void
    {
        $group = Group::factory()->create(['current_members' => 1]);
        $payment = Payment::factory()->for($group)->create(['status' => 'completed']);
        GroupMember::factory()
            ->for($group)
            ->for($payment->user, 'user')
            ->create(['status' => 'left']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('refundPayment')->once()->andReturn([
                'refund_id' => 're_456',
                'status' => 'succeeded',
                'amount' => 1999,
            ]);
        });

        app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');

        // Un membre déjà "left" ne doit pas faire descendre current_members
        // une deuxième fois.
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_refund_is_idempotent_and_does_not_call_stripe_twice(): void
    {
        $payment = Payment::factory()->refunded()->create();

        // Le paiement est déjà "refunded" : un deuxième appel ne doit
        // jamais recontacter Stripe.
        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldNotReceive('refundPayment');
            $mock->shouldNotReceive('cancelSubscription');
        });

        $result = app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');

        $this->assertSame('refunded', $result->status);
        $this->assertSame($payment->stripe_refund_id, $result->stripe_refund_id);
    }

    public function test_refund_failure_to_cancel_subscription_does_not_block_the_refund(): void
    {
        // Le webhook a peut-être déjà annulé l'abonnement Stripe : l'appel
        // cancelSubscription échoue, mais le remboursement (la partie
        // critique) doit tout de même être enregistré.
        $group = Group::factory()->create(['current_members' => 2]);
        $payment = Payment::factory()->for($group)->create(['status' => 'completed']);
        GroupMember::factory()
            ->for($group)
            ->for($payment->user, 'user')
            ->withStripeSubscription('sub_already_gone')
            ->create(['status' => 'active']);

        $this->mock(PaymentGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('refundPayment')->once()->andReturn([
                'refund_id' => 're_789',
                'status' => 'succeeded',
                'amount' => 1999,
            ]);
            $mock->shouldReceive('cancelSubscription')
                ->once()
                ->andThrow(new Exception('No such subscription'));
        });

        $refunded = app(PaymentService::class)->refundPayment($payment, 'dispute_resolved');

        $this->assertSame('refunded', $refunded->status);
    }
}
