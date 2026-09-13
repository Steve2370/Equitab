<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation PUBLIQUE d'un propriétaire de groupe — utilisée partout où
 * un visiteur anonyme ou un utilisateur non-membre peut voir "qui possède ce
 * groupe" (listes publiques, page d'invitation, détail d'un groupe). Ne
 * jamais y ajouter email, téléphone, adresse ou un identifiant Stripe : ces
 * champs sont réservés aux vues où l'utilisateur consulte SON PROPRE profil
 * (DashboardController::profile/preferences), jamais à une sortie publique.
 */
class PublicUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->display_name,
            'avatar' => $this->avatar,
            'identityStatus' => $this->identity_status,
            'trustScore' => $this->calculateTrustScore(),
        ];
    }
}
