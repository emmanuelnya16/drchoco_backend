<?php

namespace App\Admin;

use App\Account\PhoneNumber;
use App\Account\TextInput;
use App\Api\ApiException;
use App\Audit\AuditLog;
use App\Catalog\ShopCity;
use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Push\PushSubscriptions;
use App\Repository\AdminRepository;
use App\Repository\ProductRepository;
use App\Service\Auth\RefreshTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Comptes vendeurs, gérés par les super admins (owner, manager) — écran « Vendeurs (Villes) » du front.
 *
 * - Un vendeur est rattaché à Yaoundé ou à Douala ; s'il change de ville, ses articles le suivent.
 * - Désactivation, changement de mot de passe ou suppression : toutes ses sessions sont coupées aussitôt
 *   (en plus du contrôle à chaque requête, App\Security\AccountStatusChecker).
 * - Suppression refusée tant que le vendeur a des articles (409) : le désactiver garde son historique.
 * - Mot de passe : règle de App\Admin\AdminPassword ; haché (bcrypt), jamais renvoyé. S'il change (ou si le compte est
 *   désactivé), les appareils abonnés aux notifications sont retirés aussi.
 * - Chaque action est écrite dans le journal d'audit, avec son auteur.
 */
final class SellerManager
{
    public const PASSWORD_MIN_LENGTH = AdminPassword::MIN_LENGTH;

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly ProductRepository $products,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RefreshTokenService $refreshTokens,
        private readonly PushSubscriptions $pushDevices,
        private readonly AuditLog $audit,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @param array<string, mixed> $body */
    public function create(array $body, Admin $actor): Admin
    {
        $errors = [];
        $name = $this->name($body['name'] ?? null, $errors);
        $email = $this->email($body['email'] ?? null, $errors);
        $phone = $this->phone($body['phone'] ?? null, $errors);
        $password = $this->password($body['password'] ?? null, $errors, required: true, personal: [$email, $phone]);
        $city = $this->city($body['city'] ?? null, $errors);
        $this->throwIfInvalid($errors);
        $this->assertNotTaken($email, $phone, null);

        $seller = (new Admin())
            ->setName($name)
            ->setEmail($email)
            ->setPhone($phone)
            ->setRole(Admin::ROLE_SELLER)
            ->setCity($city)
            ->setIsActive(true);
        $seller->setPassword($this->hasher->hashPassword($seller, $password));

        $this->em->persist($seller);
        $this->em->flush();
        $this->audit->record('seller.created', $actor, ['seller' => $seller->getId(), 'email' => $seller->getEmail(), 'city' => $city]);

        return $seller;
    }

    /** @param array<string, mixed> $body seuls les champs présents sont modifiés */
    public function update(Admin $seller, array $body, Admin $actor): Admin
    {
        $errors = [];
        $changes = [];
        if (\array_key_exists('name', $body)) {
            $changes['name'] = $this->name($body['name'], $errors);
        }
        if (\array_key_exists('email', $body)) {
            $changes['email'] = $this->email($body['email'], $errors);
        }
        if (\array_key_exists('phone', $body)) {
            $changes['phone'] = $this->phone($body['phone'], $errors);
        }
        if (\array_key_exists('password', $body) && $body['password'] !== null && $body['password'] !== '') {
            $changes['password'] = $this->password($body['password'], $errors, required: true, personal: [$changes['email'] ?? $seller->getEmail(), $changes['phone'] ?? $seller->getPhone()]);
        }
        if (\array_key_exists('city', $body)) {
            $changes['city'] = $this->city($body['city'], $errors);
        }
        if (\array_key_exists('active', $body)) {
            if (!\is_bool($body['active'])) {
                $errors['active'] = 'Statut invalide.';
            } else {
                $changes['active'] = $body['active'];
            }
        }
        $this->throwIfInvalid($errors);
        $this->assertNotTaken($changes['email'] ?? null, $changes['phone'] ?? null, $seller);

        $cutSessions = false;
        $movedCity = null;

        if (isset($changes['name'])) {
            $seller->setName($changes['name']);
        }
        if (isset($changes['email'])) {
            $seller->setEmail($changes['email']);
        }
        if (\array_key_exists('phone', $changes)) {
            $seller->setPhone($changes['phone']);
        }
        if (isset($changes['password'])) {
            $seller->setPassword($this->hasher->hashPassword($seller, $changes['password']));
            $cutSessions = true;
        }
        if (isset($changes['city']) && $changes['city'] !== $seller->getCity()) {
            $seller->setCity($changes['city']);
            $movedCity = $changes['city'];
        }
        if (isset($changes['active'])) {
            $cutSessions = $cutSessions || ($seller->isActive() && $changes['active'] === false);
            $seller->setIsActive($changes['active']);
        }

        // Sessions et appareils coupés dans la même transaction que le nouveau mot de passe : une connexion en cours avec
        // l'ancien attend la ligne verrouillée, puis la relit changée (App\Admin\AdminLogin).
        $this->em->wrapInTransaction(function () use ($seller, $movedCity, $cutSessions): void {
            $this->em->flush();
            if ($movedCity !== null) {
                $this->products->moveSellerProductsToCity($seller, $movedCity);
            }
            if ($cutSessions) {
                $this->refreshTokens->revokeAll((int) $seller->getId(), RefreshToken::TYPE_ADMIN);
                $this->pushDevices->forgetAdmin((int) $seller->getId());
            }
        });
        $this->audit->record('seller.updated', $actor, ['seller' => $seller->getId(), 'changes' => array_diff_key($changes, ['password' => true]) + (isset($changes['password']) ? ['password' => 'changé'] : [])]);

        return $seller;
    }

    public function delete(Admin $seller, Admin $actor): void
    {
        $count = $this->products->countOwnedBy($seller);
        if ($count > 0) {
            throw new ApiException(
                409,
                'SELLER_HAS_PRODUCTS',
                sprintf('Ce vendeur a encore %d article%s : désactivez plutôt son compte.', $count, $count > 1 ? 's' : ''),
                ['productsCount' => $count],
            );
        }

        $id = (int) $seller->getId();
        $email = $seller->getEmail();
        $this->em->remove($seller);
        $this->em->flush();
        $this->refreshTokens->revokeAll($id, RefreshToken::TYPE_ADMIN);
        $this->audit->record('seller.deleted', $actor, ['seller' => $id, 'email' => $email]);
    }

    // ── Contrôles ─────────────────────────────────────────────────────────

    /** @param array<string, string> $errors */
    private function name(mixed $raw, array &$errors): string
    {
        $name = TextInput::clean($raw) ?? '';
        if (TextInput::length($name) < 2) {
            $errors['name'] = 'Indiquez le nom complet du vendeur.';
        } elseif (TextInput::length($name) > 120) {
            $errors['name'] = 'Le nom ne doit pas dépasser 120 caractères.';
        }

        return $name;
    }

    /** @param array<string, string> $errors */
    private function email(mixed $raw, array &$errors): string
    {
        $email = mb_strtolower(trim(\is_string($raw) ? $raw : ''));
        if ($email === '' || \strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Indiquez une adresse e-mail valide.';
        }

        return $email;
    }

    /**
     * Téléphone facultatif (aussi utilisable pour se connecter). Le préfixe laissé seul dans le formulaire
     * (« +237 6 ») vaut « pas de téléphone ».
     *
     * @param array<string, string> $errors
     */
    private function phone(mixed $raw, array &$errors): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!\is_string($raw)) {
            $errors['phone'] = 'Numéro de téléphone invalide.';

            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $raw);
        if ($digits === '' || \in_array($digits, ['237', '2376', '6'], true)) {
            return null;
        }
        $phone = PhoneNumber::normalizeCameroonMobile($raw);
        if ($phone === null) {
            $errors['phone'] = 'Entrez un numéro mobile camerounais valide (9 chiffres commençant par 6).';
        }

        return $phone;
    }

    /**
     * Mot de passe gardé tel quel (pas de rognage : la connexion compare exactement ce qui est saisi).
     *
     * @param array<string, string> $errors
     * @param list<?string>         $personal e-mail, téléphone… : le mot de passe ne doit pas les reprendre
     */
    private function password(mixed $raw, array &$errors, bool $required, array $personal = []): string
    {
        $password = \is_string($raw) ? $raw : '';
        if ($password === '' && !$required) {
            return '';
        }
        $error = self::passwordProblem($password, $personal);
        if ($error !== null) {
            $errors['password'] = $error;
        }

        return $password;
    }

    /**
     * Règle commune à tous les comptes de l'équipe : voir App\Admin\AdminPassword.
     *
     * @param list<?string> $personal
     */
    public static function passwordProblem(string $password, array $personal = []): ?string
    {
        return AdminPassword::problem($password, $personal);
    }

    /** @param array<string, string> $errors */
    private function city(mixed $raw, array &$errors): string
    {
        $city = ShopCity::normalize(\is_string($raw) ? $raw : null);
        if ($city === null) {
            $errors['city'] = 'Choisissez Yaoundé ou Douala.';
        }

        return (string) $city;
    }

    /** @param array<string, string> $errors */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            // Le front affiche le message tel quel : le premier problème, en clair.
            throw ApiException::validation($errors, reset($errors));
        }
    }

    private function assertNotTaken(?string $email, ?string $phone, ?Admin $except): void
    {
        if ($this->admins->isTaken($email, $phone, $except)) {
            throw new ApiException(409, 'DUPLICATE_SELLER', 'Un compte utilise déjà cet e-mail ou ce numéro de téléphone.');
        }
    }
}
