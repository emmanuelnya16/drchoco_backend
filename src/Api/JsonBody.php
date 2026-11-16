<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lecture du corps JSON d'une écriture. Corps absent, illisible ou qui n'est pas un objet : 422.
 */
final class JsonBody
{
    /** @return array<string, mixed> */
    public static function object(Request $request, bool $allowEmpty = false): array
    {
        $content = trim((string) $request->getContent());
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
}
