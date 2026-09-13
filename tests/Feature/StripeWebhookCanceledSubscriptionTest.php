<?php

namespace Tests\Feature;

use App\Features\Payment\Controllers\StripeWebhookController;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre le finding « élevé » de l'audit sur le webhook
 * customer.subscription.deleted : handleSubscriptionCanceled()
 * décrémentait current_members sans vérifier le statut précédent du
 * membre, donc un événement rejoué par Stripe (retry) ou reçu après un
 * départ déjà traité ailleurs faisait chuter le compteur sous le nombre
 * réel de membres actifs.
 *
 * On invoque la méthode privée directement par réflexion plutôt que de
 * simuler une requête HTTP signée par Stripe (Webhook::constructEvent
 * n'est pas simplement mockable) — ce test cible la logique métier, pas
 * la vérification de signature.
 */
class StripeWebhookCanceledSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function invokeHandleSubscriptionCanceled(object $subscription): void
    {
        $controller = app(StripeWebhookController::class);
        $method = new \ReflectionMethod(StripeWebhookController::class, 'handleSubscriptionCanceled');
        $method->setAccessible(true);
        $method->invoke($controller, $subscription);
    }

    public function test_decrements_current_members_when_member_was_active(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);
        $member = GroupMember::factory()->for($group)->withStripeSubscription('sub_abc')->create([
            'status' => 'active',
        ]);

        $this->invokeHandleSubscriptionCanceled((object) ['id' => 'sub_abc']);

        $member->refresh();
        $this->assertSame('left', $member->status);
        $this->assertSame('canceled', $member->subscription_status);
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_does_not_decrement_again_when_member_was_already_left(): void
    {
        $group = Group::factory()->create(['current_members' => 1]);
        $member = GroupMember::factory()->for($group)->withStripeSubscription('sub_def')->create([
            'status' => 'left',
        ]);

        $this->invokeHandleSubscriptionCanceled((object) ['id' => 'sub_def']);

        $member->refresh();
        $this->assertSame('left', $member->status);

        // Rejeu de l'événement Stripe (retry) : le compteur ne doit pas
        // redescendre une deuxième fois.
        $this->assertSame(1, $group->fresh()->current_members);
    }

    public function test_does_nothing_when_no_member_matches_the_subscription(): void
    {
        $group = Group::factory()->create(['current_members' => 2]);

        $this->invokeHandleSubscriptionCanceled((object) ['id' => 'sub_unknown']);

        $this->assertSame(2, $group->fresh()->current_members);
    }
}
