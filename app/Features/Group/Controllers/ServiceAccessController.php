<?php

namespace App\Features\Group\Controllers;

use App\Features\Group\Services\ServiceAccessDelivery;
use App\Features\Payment\Services\BillingUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceAccessController extends Controller
{
    public function __construct(private readonly ServiceAccessDelivery $delivery) {}

    public function show(Request $request, Group $group): JsonResponse
    {
        return response()->json($this->delivery->state($request->user(), $group))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function manage(Request $request, Group $group): Response
    {
        return Inertia::render('Dashboard/Groups/Access', [
            'group' => $this->delivery->ownerState($request->user(), $group),
        ]);
    }

    public function store(Request $request, Group $group, ?GroupMember $member = null): JsonResponse
    {
        try {
            $this->delivery->deliver($request->user(), $group, $member, $request->only([
                'invitation_url', 'invitation_sent', 'credential_email', 'credential_password', 'credential_notes',
            ]));
        } catch (BillingUnavailable) {
            return $this->retryResponse();
        }

        return response()->json(['message' => 'Accès mis à disposition. Il sera visible uniquement par les membres autorisés ayant payé.'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, Group $group, GroupMember $member): JsonResponse
    {
        $request->validate(['removed_at_provider' => ['required', 'accepted']]);
        try {
            $this->delivery->confirmRevocation($request->user(), $group, $member);
        } catch (BillingUnavailable) {
            return $this->retryResponse();
        }

        return response()->json(['message' => 'Retrait chez le fournisseur déclaré par le propriétaire.']);
    }

    private function retryResponse(): JsonResponse
    {
        return response()->json(['message' => 'Une autre opération est en cours. Vérifiez l’état de l’accès puis réessayez.'], 503)
            ->header('Retry-After', '3')->header('Cache-Control', 'private, no-store');
    }
}
