<?php

namespace App\Media;

/**
 * Retire les métadonnées d'une image (position GPS, appareil, date, commentaires) sans la réencoder, en PHP pur
 * (l'extension GD n'est pas toujours présente) :
 * - JPEG : segments APP1 (EXIF, XMP), APP3 à APP12, APP13 (IPTC), APP15 et commentaires retirés ; JFIF (APP0),
 *   profil de couleurs (APP2) et Adobe (APP14) gardés ;
 * - PNG : blocs tEXt, zTXt, iTXt, eXIf et tIME retirés ;
 * - WebP : blocs EXIF et XMP retirés (et leurs indicateurs dans l'en-tête VP8X).
 * Renvoie null si le fichier n'a pas la structure attendue.
 */
final class ImageMetadataStripper
{
    public static function strip(string $bytes, string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => self::jpeg($bytes),
            'image/png'  => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default      => null,
        };
    }

    private static function jpeg(string $b): ?string
    {
        $len = \strlen($b);
        if ($len < 4 || substr($b, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        while ($pos < $len) {
            if ($b[$pos] !== "\xFF") {
                return null;
            }
            // Octets de remplissage 0xFF avant un marqueur.
            while ($pos < $len && $b[$pos] === "\xFF") {
                ++$pos;
            }
            if ($pos >= $len) {
                return null;
            }
            $marker = \ord($b[$pos]);
            ++$pos;

            if ($marker === 0xD9) { // fin de l'image
                return $out . "\xFF\xD9";
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { // marqueurs sans longueur
                $out .= "\xFF" . \chr($marker);
                continue;
            }
            if ($pos + 2 > $len) {
                return null;
            }
            $segLen = (\ord($b[$pos]) << 8) | \ord($b[$pos + 1]);
            if ($segLen < 2 || $pos + $segLen > $len) {
                return null;
            }
            $segment = substr($b, $pos, $segLen);
            $pos += $segLen;

            if ($marker === 0xDA) { // début des données de l'image : tout le reste est recopié
                return $out . "\xFF\xDA" . $segment . substr($b, $pos);
            }
            $isMetadata = $marker === 0xFE // commentaire
                || $marker === 0xE1 || ($marker >= 0xE3 && $marker <= 0xED) || $marker === 0xEF;
            if (!$isMetadata) {
                $out .= "\xFF" . \chr($marker) . $segment;
            }
        }

        return null;
    }

    private static function png(string $b): ?string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (!str_starts_with($b, $signature)) {
            return null;
        }
        $drop = ['tEXt' => true, 'zTXt' => true, 'iTXt' => true, 'eXIf' => true, 'tIME' => true];
        $out = $signature;
        $pos = 8;
        $len = \strlen($b);
        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            $total = 12 + $chunkLen;
            if ($chunkLen < 0 || $pos + $total > $len) {
                return null;
            }
            if (!isset($drop[$type])) {
                $out .= substr($b, $pos, $total);
            }
            $pos += $total;
            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    private static function webp(string $b): ?string
    {
        $len = \strlen($b);
        if ($len < 12 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
            return null;
        }
        $body = '';
        $pos = 12;
        while ($pos + 8 <= $len) {
            $fourcc = substr($b, $pos, 4);
            $size = unpack('V', substr($b, $pos + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $len) {
                return null;
            }
            $chunk = substr($b, $pos, 8 + min($padded, $len - $pos - 8));
            $pos += 8 + $padded;
            if ($fourcc === 'EXIF' || $fourcc === 'XMP ') {
                continue;
            }
            if ($fourcc === 'VP8X' && $size >= 1) {
                // Indicateurs : bit 3 = EXIF, bit 2 = XMP.
                $chunk[8] = \chr(\ord($chunk[8]) & ~0x0C);
            }
            $body .= $chunk;
        }
        if ($body === '') {
            return null;
        }

        return 'RIFF' . pack('V', 4 + \strlen($body)) . 'WEBP' . $body;
    }
}
