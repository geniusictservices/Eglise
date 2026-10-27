<?php

declare(strict_types=1);

namespace App\Core;

/** Validation par le PIN d'un supérieur refusée : message, statut HTTP et code pour la caisse (« pin », « validation »). */
final class PinRefuse extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statut, public readonly string $raison = 'pin')
    {
        parent::__construct($message);
    }
}
