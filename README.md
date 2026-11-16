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
php bin/console app:create-admin        # gérant ou vendeur (Yaoundé / Douala)
php bin/console app:diagnostic          # vérifie base, JWT, Cloudinary, Twilio, codes SMS, PawaPay
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public
```

L’API répond sous `http://127.0.0.1:8000/api` (ex. `GET /api/health`).

## Données de départ (`app:seed`)

Les fichiers `data/seed/*.json` reprennent exactement les données de démonstration du front (24 articles répartis entre Yaoundé et Douala, 3 catégories, 4 looks, 15 villes, agences, 9 pages, réglages de paiement USSD).

- `php bin/console app:seed` : ajoute ce qui manque, sans rien écraser ni supprimer (sans danger à relancer).
- `--only=pages,settings` : seulement certaines parties (`categories`, `products`, `looks`, `delivery`, `pages`, `settings`).
- `--update` : reprend les valeurs du fichier pour ce qui existe déjà (confirmation demandée).
- Pour régénérer les fichiers depuis les mocks du front : voir `tools/export-front-fixtures.ts`.

## Connexion des clients par code SMS (P2)

Le client se connecte avec son numéro : un code à 6 chiffres part par SMS (Twilio), valable 5 min.

| Réglage (`.env.local`) | Rôle |
|---|---|
| `OTP_SMS_TRANSPORT=twilio` | Envoi réel par Twilio (par défaut). |
| `OTP_SMS_TRANSPORT=log` | Développement seulement : aucun SMS, le code est écrit dans `var/log/dev.log` (ignoré en production). |
| `OTP_WEBOTP_HOST=drchoco.cm` | Domaine de l’app : le SMS se termine par `@drchoco.cm #123456` (remplissage automatique sur Android). |
| `OTP_DAILY_SMS_LIMIT=300` | Plafond de SMS sur 24 h pour toute la boutique (protection des coûts). |

- **Compte Twilio d’essai** (actuel) : les SMS ne partent que vers les numéros vérifiés dans la console Twilio (*Phone Numbers > Verified Caller IDs*), précédés de « Sent from your Twilio trial account ». Pour tester avec un autre numéro, utiliser `OTP_SMS_TRANSPORT=log`. Avec le compte payant, il suffit de remplacer `TWILIO_*` dans `.env.local`.
- `php bin/console app:sms:test 690000001` : envoie un SMS d’essai (confirmation demandée).
- SMS non parti : le client voit « Impossible d’envoyer le SMS pour le moment » ; `var/log/prod.log` contient « Code de connexion non envoyé… » (numéro masqué) et la raison exacte donnée par Twilio est dans le journal PHP (`error_log` de l’hébergement, jamais servi au public).
- Limites : renvoi après 60 s, 3 demandes en 10 min et 10 par jour par numéro, 30 par heure par adresse IP ; 5 codes faux bloquent le numéro 10 min.
- Session du client : cookie httpOnly `drc_refresh` (90 jours glissants). Il n’est posé que si l’app et l’API sont sur le **même domaine**.

### Tâche planifiée (cPanel)

Une fois par jour : `php bin/console app:purge` (codes SMS de plus de 48 h, sessions expirées, fichiers de verrou de `var/lock` inutilisés depuis 48 h).

## Administration : comptes de l’équipe (P3)

Trois rôles : **owner** (propriétaire) et **manager** (gérant) sont super admins ; **seller** (vendeur) est rattaché à Yaoundé ou à Douala et ne voit que sa ville (ses articles et ceux de sa ville sans vendeur). Les droits sont contrôlés par le serveur, pas seulement par le menu du front.

- Premier compte : `php bin/console app:create-owner` (mot de passe masqué, 10 caractères minimum).
- Vendeurs : depuis l’administration, écran « Vendeurs (Villes) » (création, ville, activation), ou `php bin/console app:create-admin --role=seller --city=Douala`. Gérant : `app:create-admin --role=manager`.
- Connexion avec l’e-mail ou le téléphone ; 5 mots de passe faux bloquent l’identifiant 15 min. Session admin séparée de celle des clients (cookie `drc_admin_refresh`, 12 h au plus).
- Mots de passe : 10 caractères au moins, ni mot de passe connu (dont « choco2026 »), ni l’e-mail ou le téléphone du compte.
- Un vendeur désactivé, ou dont le mot de passe change, perd l’accès immédiatement (sessions et jeton en cours coupés). Un vendeur qui change de ville emmène ses articles. Suppression refusée tant qu’il a des articles : le désactiver.
- Dépannage de n’importe quel compte (propriétaire compris) : `php bin/console app:admin:update <e-mail ou téléphone> --password | --disable | --enable`. Le dernier propriétaire actif ne peut pas être désactivé.
- Compte bloqué après 5 mots de passe faux (ou 20 depuis une même adresse IP) : attendre 15 min, ou `php bin/console cache:pool:clear cache.rate_limiter`.

## Brancher le front sur cette API

Dans le front (`.env.local`) :

```env
VITE_API_MOCK=true
VITE_API_BASE_URL=/api
VITE_DEV_API_PROXY=http://127.0.0.1:8000
VITE_API_REAL_ROUTES=/categories,/products,/search,/looks,/settings/public,/delivery,/pages,/cart/validate,/client-errors,/auth,/me,/favorites
```

Administration (P3) : ajouter `/admin/auth,/admin/sellers,/admin/customers,/admin/search,/admin/dashboard`. Les autres écrans d’administration (catalogue, réservations, réglages) restent simulés et ne reconnaissent pas le vrai jeton admin : ils afficheront une erreur jusqu’aux phases P4, P6 et P8.

- `VITE_DEV_API_PROXY` relaie `/api` du serveur Vite vers cette API : même domaine, comme en production, indispensable au cookie de session.
- **Bascule progressive** : les routes listées dans `VITE_API_REAL_ROUTES` partent vers l’API, le reste reste simulé (tant que `/reservations` est simulé, les mocks montrent les réservations de démonstration).
- **Tout réel** : `VITE_API_MOCK=false` (les écrans de réservation attendent la phase P5).

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
