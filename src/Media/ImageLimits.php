<?php

namespace App\Media;

/**
 * Dimensions acceptées pour une image reçue. Un fichier de quelques kilo-octets peut annoncer des milliards de pixels :
 * l'ouvrir bloquerait le téléphone de la personne qui vérifie. Le front envoie des images de 1 600 px au plus.
 */
final class ImageLimits
{
    public const MAX_SIDE = 12_000;
    public const MAX_PIXELS = 40_000_000;

    public static function accepts(int $width, int $height): bool
    {
        return $width >= 1 && $height >= 1
            && $width <= self::MAX_SIDE && $height <= self::MAX_SIDE
            && $width * $height <= self::MAX_PIXELS;
    }
}
