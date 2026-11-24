<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lecture du corps JSON d'une écriture. Corps absent, illisible ou qui n'est pas un objet : 422 ; trop gros : 413.
 *
 * La taille est contrôlée AVANT de lire le JSON : quelques Mo de clés bien choisies suffiraient à occuper un processus
 * PHP pendant des dizaines de secondes. 64 Ko suffisent partout, sauf pour les formulaires longs de l'administration.
 */
final class JsonBody
{
    public const MAX_BYTES = 65_536;
    /** Formulaires longs de l'administration : article avec toutes ses variantes, page de 100 000 caractères. */
    public const MAX_BYTES_EDITOR = 1_048_576;

    /** @return array<string, mixed> */
    public static function object(Request $request, bool $allowEmpty = false, int $maxBytes = self::MAX_BYTES): array
    {
        $content = trim(self::content($request, $maxBytes));
        if ($content === '' && $allowEmpty) {
            return [];
        }

        try {
            $data = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        // Un objet JSON vide « {} » arrive comme un tableau vide : accepté.
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::validation(['body' => 'Le corps de la requête doit être un objet JSON.']);
        }

        return $data;
    }

    /** Corps brut de la requête, refusé (413) au-delà de la taille permise. */
    public static function content(Request $request, int $maxBytes = self::MAX_BYTES): string
    {
        $tooLarge = new ApiException(413, 'PAYLOAD_TOO_LARGE', 'La requête est trop volumineuse.');
        if ((int) $request->headers->get('Content-Length', '0') > $maxBytes) {
            throw $tooLarge;
        }
        $content = (string) $request->getContent();
        if (\strlen($content) > $maxBytes) {
            throw $tooLarge;
        }

        return $content;
    }
}
