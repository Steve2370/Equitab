<?php

namespace App\Features\Payment\Services;

use RuntimeException;

final class BillingUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La vérification du paiement est temporairement indisponible. Votre demande est conservée ; veuillez réessayer.');
    }
}
