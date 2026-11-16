<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Chemin de la requête tel que le routeur et le pare-feu de Symfony le comparent (décodé : « /api/%61dmin »
 * = « /api/admin »). Les contrôles faits sur le chemin (origine, cache, type de jeton) l'utilisent aussi :
 * une URL encodée ne peut pas contourner un contrôle tout en atteignant la route.
 */
final class RequestPath
{
    public static function of(Request $request): string
    {
        return rawurldecode($request->getPathInfo());
    }
}
