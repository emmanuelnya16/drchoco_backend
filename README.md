# drchoco_backend

API Symfony 7.4 de l’application **DR Choco L’Incompris** (PWA e-commerce : réservation, paiement Mobile Money par code USSD, suivi, administration multi-vendeurs Yaoundé / Douala).

- Front : projet `dr_choco` (React). Contrat d’API : `docs/CONTRAT_API.md` et `docs/CONTRAT_API_ADMIN.md` du front.
- Avancement et phases : voir [PLAN.md](PLAN.md).

## Prérequis

- PHP 8.2 ou plus, avec les extensions `pdo_mysql`, `openssl`, `sodium`, `curl`, `mbstring`, `fileinfo`
- MariaDB 10.4+ / MySQL 8, Composer 2

## Installation locale

```bash
composer install
# Créer .env.local (jamais versionné) avec : DATABASE_URL, JWT_PASSPHRASE, CLOUDINARY_URL,
# TWILIO_*, ADMIN_PHONE_NUMBER, PAWAPAY_* (PAWAPAY_ENABLED=0) — voir la liste dans .env
# Copier les clés JWT dans config/jwt/private.pem et config/jwt/public.pem (jamais versionnées)
php bin/console doctrine:migrations:migrate
php bin/console app:create-owner        # compte propriétaire (mot de passe demandé, masqué)
php bin/console app:diagnostic          # vérifie base, JWT, Cloudinary, Twilio, PawaPay
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public
```

L’API répond sous `http://127.0.0.1:8000/api` (ex. `GET /api/health`).

## Tests

```bash
# .env.test.local doit contenir JWT_PASSPHRASE (jamais versionné)
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test -n
php bin/phpunit
```

## Règles du projet

- Toute erreur renvoie `{ "code", "message", "details"? }` en français (contrat §2.2).
- Les dates sont en UTC ; les montants en entiers FCFA.
- Aucun secret dans le dépôt : `.env.local`, `.env.*.local` et `config/jwt/*.pem` sont ignorés par git.
