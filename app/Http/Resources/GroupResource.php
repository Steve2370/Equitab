<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sortie PUBLIQUE d'un groupe (API mobile non authentifiée : GET /api/groups,
 * GET /api/groups/{id}). Liste blanche stricte — c'est justement l'absence
 * d'une telle liste blanche qui laissait auparavant credential_email,
 * credential_password, credential_notes et invite_token fuiter en clair via
 * la sérialisation JSON par défaut du modèle Group (cast "encrypted", donc
 * déchiffrés automatiquement à la lecture). Ne jamais ajouter ces champs
 * ici, ni les identifiants Stripe du propriétaire (voir PublicUserResource).
 */
class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'subscription' => [
                'name' => $this->subscription?->name,
                'slug' => $this->subscription?->slug,
                'tier' => $this->subscription?->tier,
            ],
            'owner' => new PublicUserResource($this->whenLoaded('owner')),
            'tier' => $this->tier,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'pricePerMember' => $this->status === 'open' && $this->current_members < $this->max_members
                ? $this->calculatePricePerMemberIfJoined()
                : $this->calculateCurrentPricePerMember(),
            'currentMembers' => $this->current_members,
            'maxMembers' => $this->max_members,
            'spotsAvailable' => max(0, $this->max_members - $this->current_members),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
