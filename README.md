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
php bin/console app:seed                # données de départ : catalogue, looks, villes, agences, pages, réglages
php bin/console app:create-owner        # compte propriétaire (mot de passe demandé, masqué)
php bin/console app:diagnostic          # vérifie base, JWT, Cloudinary, Twilio, PawaPay
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public
```

L’API répond sous `http://127.0.0.1:8000/api` (ex. `GET /api/health`).

## Données de départ (`app:seed`)

Les fichiers `data/seed/*.json` reprennent exactement les données de démonstration du front (24 articles répartis entre Yaoundé et Douala, 3 catégories, 4 looks, 15 villes, agences, 9 pages, réglages de paiement USSD).

- `php bin/console app:seed` : ajoute ce qui manque, sans rien écraser ni supprimer (sans danger à relancer).
- `--only=pages,settings` : seulement certaines parties (`categories`, `products`, `looks`, `delivery`, `pages`, `settings`).
- `--update` : reprend les valeurs du fichier pour ce qui existe déjà (confirmation demandée).
- Pour régénérer les fichiers depuis les mocks du front : voir `tools/export-front-fixtures.ts`.

## Brancher le front sur cette API

Dans le front (`.env.local`), avec `VITE_API_BASE_URL=http://127.0.0.1:8000/api` :

- **Bascule progressive** (recommandé tant que toutes les phases ne sont pas finies) : garder `VITE_API_MOCK=true` et lister les routes déjà prêtes ; elles partent vers l’API, le reste reste simulé :
  `VITE_API_REAL_ROUTES=/categories,/products,/search,/looks,/settings/public,/delivery,/pages,/cart/validate,/client-errors`
- **Tout réel** : `VITE_API_MOCK=false` (les écrans de compte et de réservation attendent les phases P2 et P5).

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
