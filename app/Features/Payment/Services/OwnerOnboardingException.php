<?php

namespace App\Features\Payment\Services;

use RuntimeException;

final class OwnerOnboardingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La vérification Stripe est indisponible pour le moment. Votre progression est conservée. Réessayez plus tard.');
    }
}
