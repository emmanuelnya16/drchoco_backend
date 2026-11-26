<?php

namespace App\Auth;

use App\Api\ApiException;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fin commune des deux connexions du client (code SMS, WhatsApp) : compte créé à la première connexion, jeton d'accès
 * (JWT lexik, configuration inchangée) et session longue (cookie httpOnly, 90 jours).
 *
 * À appeler dans la transaction de l'appelant, après avoir marqué son code ou sa demande comme utilisé : si l'ouverture
 * échoue (panne de la base…), l'appelant annule tout et le client peut recommencer.
 */
final class LoginOpener
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    /**
     * @return array{user: User, isNewUser: bool, accessToken: string, cookie: Cookie}
     *
     * @throws ApiException 403 ACCOUNT_BLOCKED (rien n'est encore écrit pour la session)
     */
    public function open(string $phone, \DateTimeImmutable $now, Request $request): array
    {
        $user = $this->users->findByPhone($phone);
        $isNewUser = false;
        if ($user === null) {
            $user = (new User($now))->setPhone($phone);
            $this->em->persist($user);
            $isNewUser = true;
        } elseif ($user->isBlocked()) {
            throw self::blocked();
        }

        $user->setLastLoginAt($now);
        $this->em->flush(); // un nouveau compte reçoit ici son identifiant, nécessaire au JWT et à la session

        $accessToken = $this->refreshTokens->createAccessToken($user);
        $session = $this->refreshTokens->createForUser($user, $request);

        return ['user' => $user, 'isNewUser' => $isNewUser, 'accessToken' => $accessToken, 'cookie' => $session['cookie']];
    }

    public static function blocked(): ApiException
    {
        return new ApiException(403, 'ACCOUNT_BLOCKED', 'Ce compte est suspendu. Contactez la boutique sur WhatsApp.');
    }
}
