<?php

namespace App\Media;

/**
 * Retire les métadonnées d'une image (position GPS, appareil, date, commentaires) sans la réencoder, en PHP pur
 * (l'extension GD n'est pas toujours présente) :
 * - JPEG : segments APP1 (EXIF, XMP), APP3 à APP12, APP13 (IPTC), APP15 et commentaires retirés, partout dans le fichier ;
 *   JFIF (APP0, sans sa miniature), profil de couleurs (APP2 ICC) et Adobe (APP14) gardés ; tout ce qui suit la fin de
 *   l'image (seconde image, vidéo des photos animées, données ajoutées) est retiré ;
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

    /** Marqueurs utiles entre deux passages d'une image JPEG : tables, redémarrage, nombre de lignes, passage suivant. */
    private const JPEG_BETWEEN_SCANS = [0xC4 => true, 0xCC => true, 0xDB => true, 0xDC => true, 0xDD => true, 0xDA => true];

    private static function jpeg(string $b): ?string
    {
        $len = \strlen($b);
        if ($len < 4 || substr($b, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $scanned = false;
        while ($pos < $len) {
            if ($b[$pos] !== "\xFF") {
                return null;
            }
            // Octets de remplissage 0xFF avant un marqueur.
            while ($pos < $len && $b[$pos] === "\xFF") {
                ++$pos;
            }
            if ($pos >= $len) {
                return $scanned ? $out . "\xFF\xD9" : null;
            }
            $marker = \ord($b[$pos]);
            ++$pos;

            if ($marker === 0xD9) { // fin de l'image : la suite du fichier est ignorée
                return $scanned ? $out . "\xFF\xD9" : null;
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

            if ($marker === 0xDA) { // passage : ses données vont jusqu'au prochain marqueur
                $scanned = true;
                $end = self::endOfScan($b, $pos, $len);
                $out .= "\xFF\xDA" . $segment . substr($b, $pos, $end - $pos);
                $pos = $end;
                continue;
            }
            if ($scanned) {
                if (isset(self::JPEG_BETWEEN_SCANS[$marker])) {
                    $out .= "\xFF" . \chr($marker) . $segment;
                }
                continue;
            }
            $segment = self::jpegSegment($marker, $segment);
            if ($segment !== null) {
                $out .= "\xFF" . \chr($marker) . $segment;
            }
        }

        return $scanned ? $out . "\xFF\xD9" : null; // fichier coupé : image gardée jusqu'où elle va
    }

    /** Segment d'en-tête à garder (null : métadonnée retirée). */
    private static function jpegSegment(int $marker, string $segment): ?string
    {
        return match (true) {
            // JFIF : sans sa miniature (elle peut montrer l'image d'avant un recadrage) ; JFXX n'est qu'une miniature.
            $marker === 0xE0 => substr($segment, 2, 5) === "JFIF\0" && \strlen($segment) >= 16 ? "\x00\x10" . substr($segment, 2, 12) . "\x00\x00" : null,
            // APP2 : profil de couleurs seulement (« MPF » annonce d'autres images dans le fichier).
            $marker === 0xE2 => substr($segment, 2, 12) === "ICC_PROFILE\0" ? $segment : null,
            $marker === 0xFE, $marker === 0xE1, $marker >= 0xE3 && $marker <= 0xED, $marker === 0xEF => null,
            default => $segment,
        };
    }

    /** Position du premier vrai marqueur après les données d'un passage (FF00 et RST en font partie). */
    private static function endOfScan(string $b, int $pos, int $len): int
    {
        while (($pos = strpos($b, "\xFF", $pos)) !== false) {
            $next = $pos + 1 < $len ? \ord($b[$pos + 1]) : -1;
            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $pos += 2;
            } elseif ($next === 0xFF) {
                ++$pos; // octet de remplissage
            } else {
                return $pos;
            }
            if ($pos >= $len) {
                break;
            }
        }

        return $len;
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
