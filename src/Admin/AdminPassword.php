<?php

namespace App\Admin;

use App\Catalog\TextFolder;

/**
 * Règle commune aux mots de passe de toute l'équipe (vendeurs, gérants, propriétaire) : 10 caractères au moins, ni
 * espace au début ou à la fin, ni mot de passe connu ou tiré du nom de la boutique, d'une ville, d'une suite de touches
 * ou d'une seule répétition (même suivi d'une année ou de symboles), ni l'e-mail ou le téléphone du compte.
 */
final class AdminPassword
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    private const MIN_DISTINCT_CHARACTERS = 5;

    /** Mots refusés s'ils forment tout le mot de passe, une fois les chiffres et les symboles retirés. */
    private const FORBIDDEN_CORES = [
        'choco', 'drchoco', 'chocodr', 'docteurchoco', 'chocolat', 'drchocolat', 'lincompris', 'incompris', 'drchocolincompris',
        'chocolincompris', 'boutique', 'drchocoboutique', 'boutiquechoco', 'drchocoadmin', 'sodiserv',
        'motdepasse', 'monmotdepasse', 'password', 'passwort', 'passw', 'passe', 'secret', 'bonjour', 'bienvenue', 'soleil', 'jesus', 'jesustaime', 'jetaime',
        'azerty', 'azertyuiop', 'azertyui', 'qwerty', 'qwertyuiop', 'qsdfghjklm', 'wxcvbn', 'azertyqsdfgh', 'abcdef', 'abcdefgh', 'iloveyou', 'welcome',
        'letmein', 'admin', 'administrateur', 'administrator', 'superadmin', 'owner', 'proprietaire', 'manager', 'gerant', 'vendeur', 'seller', 'root', 'test', 'demo',
        'yaounde', 'douala', 'cameroun', 'cameroon', 'bafoussam', 'mokolo', 'akwa', 'afrique', 'football', 'mtn', 'orange', 'orangemoney', 'momo',
    ];

    /** @param list<?string> $personal e-mail, téléphone… : le mot de passe ne doit pas les reprendre */
    public static function problem(string $password, array $personal = []): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_LENGTH);
        }
        if (mb_strlen($password) > self::MAX_LENGTH) {
            return sprintf('Le mot de passe ne doit pas dépasser %d caractères.', self::MAX_LENGTH);
        }
        if ($password !== trim($password)) {
            return 'Le mot de passe ne doit pas commencer ni finir par une espace.';
        }
        if (self::isTooCommon($password)) {
            return 'Ce mot de passe est trop connu : choisissez un mot de passe personnel.';
        }

        $folded = mb_strtolower($password);
        foreach ($personal as $value) {
            $value = mb_strtolower(trim((string) $value));
            $local = str_contains($value, '@') ? strstr($value, '@', true) : $value;
            $digits = (string) preg_replace('/\D/', '', $value);
            if ($value !== '' && ($folded === $value || $folded === $local || ($digits !== '' && \strlen($digits) >= 9 && str_contains($folded, substr($digits, -9))))) {
                return 'Le mot de passe ne doit pas reprendre l’e-mail ou le téléphone du compte.';
            }
        }

        return null;
    }

    private static function isTooCommon(string $password): bool
    {
        if (preg_match('/^\d+$/', $password) === 1) {
            return true; // seulement des chiffres
        }
        if (\count(array_unique(mb_str_split(mb_strtolower($password)))) < self::MIN_DISTINCT_CHARACTERS) {
            return true; // « aaaaaaaaab », « 1a1a1a1a1a »
        }

        // « P@ssw0rd2026 », « Choco2026! », « Ch0c0-2026 » : le mot qui reste est comparé, sans année, avec et sans les
        // remplacements habituels de lettres par des chiffres ou des symboles.
        $folded = TextFolder::fold($password);
        foreach ([$folded, (string) preg_replace('/(?:19|20)\d\d/', '', $folded), (string) preg_replace('/^[^a-z]+|[^a-z]+$/', '', $folded)] as $text) {
            foreach ([$text, strtr($text, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '€' => 'e', '!' => 'i'])] as $variant) {
                $core = (string) preg_replace('/[^a-z]+/', '', $variant);
                if ($core !== '' && \in_array($core, self::FORBIDDEN_CORES, true)) {
                    return true;
                }
            }
        }

        return self::isSequence($folded);
    }

    /** Suite de lettres ou de chiffres qui se suivent (« abcdefghij », « 9876543210 »), même avec un ajout court. */
    private static function isSequence(string $text): bool
    {
        $steps = 0;
        for ($i = 1, $n = \strlen($text); $i < $n; ++$i) {
            if (abs(\ord($text[$i]) - \ord($text[$i - 1])) === 1) {
                ++$steps;
            }
        }

        return $steps >= max(6, (int) ceil((\strlen($text) - 1) * 0.7));
    }
}
