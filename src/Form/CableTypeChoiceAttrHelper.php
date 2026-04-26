<?php

namespace App\Form;

use App\Entity\CableType;

/**
 * Atributy <option> pro EntityType kabelu — text v DOM 1:1 s entitou (žádný rozjezd s JSON z Twig).
 */
final class CableTypeChoiceAttrHelper
{
    public static function forCableType(CableType $c): array
    {
        return [
            'data-family' => $c->getFamily(),
            'data-b64-cable-full' => base64_encode((string) ($c->getFullDescription() ?? '')),
            'data-cable-name' => $c->getName(),
        ];
    }
}
