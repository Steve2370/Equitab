<?php

namespace App\Features\Payment\Controllers;

use App\Features\Payment\Services\StripeEventProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Throwable;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeEventProcessor $processor): JsonResponse
    {
        $event = null;

        // Destinations do not reliably send Stripe-Account. Verify the raw
        // request with either configured secret; never trust that header.
        foreach (array_filter([
            config('services.stripe.webhook_secret'),
            config('services.stripe.connect_webhook_secret'),
        ]) as $secret) {
            try {
                $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret);
                break;
            } catch (Throwable) {
                // SDK exception messages can contain request data.
            }
        }

        if ($event === null) {
            Log::warning('Signature du webhook Stripe invalide.');

            return response()->json(['error' => 'Webhook invalide.'], 400);
        }

        try {
            $processor->process($event->toArray());
        } catch (Throwable) {
            // No success receipt is written on failure. Stripe can retry the
            // same event; do not expose provider payloads or exception details.
            Log::warning('Traitement du webhook Stripe temporairement indisponible.');

            return response()->json(['error' => 'Synchronisation temporairement indisponible.'], 503);
        }

        return response()->json(['received' => true]);
    }
}
