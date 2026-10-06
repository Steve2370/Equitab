<?php

namespace App\Features\Group\Exceptions;

use RuntimeException;

class PublicationUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La publication n’a pas pu être confirmée. Votre brouillon est conservé : vous pouvez réessayer sans créer de doublon.');
    }
}
