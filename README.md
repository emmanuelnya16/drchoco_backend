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

## Catalogue de l’administration et images (P4)

- Articles : création, modification, stock rapide et suppression depuis l’écran « Catalogue ». Un vendeur gère ses articles et ceux de sa ville sans vendeur ; ce qu’il crée lui est rattaché, dans sa ville. Le super admin choisit la ville (et peut rattacher un vendeur).
- Les variantes (couleur × taille) gardent leur identifiant d’un enregistrement à l’autre : paniers et réservations restent valables.
- Supprimer un article le retire de la vente partout (site, looks, recherche) ; son historique est gardé et son adresse redevient libre.
- Catégories, sous-catégories et looks : écran « Looks & Catégories ». Retirer une sous-catégorie laisse ses articles dans la catégorie.
- Images : `POST /api/admin/media` (JPEG, PNG ou WebP, 5 Mo au plus) les envoie sur Cloudinary, dossiers `drchoco/products`, `drchoco/categories`, `drchoco/looks`. Le front y envoie les photos choisies dans l’administration. Sur l’hébergement, `upload_max_filesize` et `post_max_size` (PHP) doivent dépasser 5 Mo.

## Réservations et paiement Mobile Money (P5)

- Une réservation ne contient que des articles d’**une seule ville** (Yaoundé ou Douala) ; sinon `422 MIXED_CITIES`.
- **Retrait** à la boutique de cette ville (gratuit). **Livraison** : à domicile dans la ville de la boutique (prix `homeDeliveryFee` de la boutique), sinon par une **agence** choisie par le client, au prix de cette agence (champ `fee` de l’agence ; vide = prix de sa ville). Une agence peut être réservée à une boutique de départ (`departure_city`). Les boutiques (adresse, horaires, prix de livraison à domicile) sont dans les réglages publics (`shops`).
- Le stock est retiré à la réservation, sous verrou (deux clients ne peuvent pas prendre la dernière pièce), et remis en vente une seule fois si la réservation expire ou est annulée.
- Délai de paiement : `reservationHoldHours` (24 h). Une réservation « en attente de validation » (preuve envoyée) n’expire jamais.
- Preuves de paiement : `POST /api/reservations/{ref}/proof` (JPEG, PNG ou WebP, 10 Mo au plus ; 20 envois par heure et par client). Rangées **hors du site public** dans `var/private/proofs/`, sans leurs métadonnées (GPS, appareil), servies par des liens signés valables 1 h (`/api/media/private/proofs/{id}`). Une même capture ne peut pas payer deux réservations. À sauvegarder avec la base. Sur l’hébergement, `upload_max_filesize` doit dépasser 11 Mo et `post_max_size` 31 Mo (3 photos du colis).
- Routes client : `POST /api/reservations` (en-tête `Idempotency-Key`), `GET /api/reservations?status=reserved`, `GET /api/reservations?scope=active|done&page=`, `GET /api/reservations/summary`, `GET /api/reservations/{ref}`, `POST /api/reservations/{ref}/proof`, `POST /api/reservations/{ref}/cancel`. La réservation d’un autre client répond 404.
- La validation des paiements et les étapes de la boutique sont décrites ci-dessous (P6) ; les notifications push arrivent en P7 (en attendant, nouvelles réservations, preuves reçues et rappels sont écrits dans `var/log`).

### Tâches planifiées des réservations (cPanel)

- Toutes les 5 minutes : `php bin/console app:reservations:expire` (réservations non payées à l’échéance → expirées, articles remis en vente).
- Toutes les 15 minutes : `php bin/console app:reservations:remind` (rappel, une seule fois, quand il reste moins de 2 h).

## Gestion des réservations par l’équipe (P6)

- **Qui fait quoi** : le super admin (propriétaire, gérant) voit tout, **valide ou rejette les paiements** et **annule** ; un vendeur voit seulement les réservations qui contiennent ses articles (ou des articles sans vendeur de sa ville), prépare, photographie le colis, le remet et ajoute des notes. Hors de son périmètre : 404 ; paiement ou annulation : 403.
- **Parcours** : retrait = payée → en préparation → prête en boutique (photos) → retirée ; livraison = payée → en préparation → remise à l’agence ou au livreur (photos) → en route → livrée. Le serveur refuse toute autre étape (`422 INVALID_STATUS`, `INVALID_RECEPTION_MODE`, `409 ALREADY_FINAL`). Un double clic ne crée pas de seconde étape.
- **Paiement rejeté** : le motif est montré au client, qui a **au moins 6 h** pour renvoyer une preuve (l’échéance d’origine n’est jamais raccourcie). Validation « manuelle » sans preuve possible tant que l’échéance n’est pas passée.
- **Photos du colis** : 1 à 3 photos (JPEG, PNG ou WebP, 10 Mo chacune) envoyées en `multipart` (`photos[]`), rangées hors du site public dans `var/private/packages/`, sans métadonnées, montrées à l’équipe et au client par des liens signés valables 1 h (`/api/media/private/package-photos/{id}`). À sauvegarder avec la base.
- **Annulation par la boutique** : articles remis en vente tout de suite ; le motif est affiché au client. Remboursement d’un paiement déjà validé : par Mobile Money, hors de l’application.
- Notes internes : jamais montrées au client.
- Routes : `GET /api/admin/reservations` (onglets `tab`, `status`, `receptionMode`, `city`, `shopCity`, `operator`, `q`, `page`, `pageSize`), `GET /api/admin/reservations/{ref}`, `POST …/{ref}/validate-payment`, `…/reject-payment`, `…/status`, `…/package-ready`, `…/cancel`, `…/notes`. La recherche rapide, la liste des clients (nombre de réservations, total payé) et les compteurs du tableau de bord utilisent maintenant les réservations.

## Brancher le front sur cette API

Dans le front (`.env.local`) :

```env
VITE_API_MOCK=true
VITE_API_BASE_URL=/api
VITE_DEV_API_PROXY=http://127.0.0.1:8000
VITE_API_REAL_ROUTES=/categories,/products,/search,/looks,/settings/public,/delivery,/pages,/cart/validate,/client-errors,/auth,/me,/favorites
```

Réservations (P5) : ajouter `/reservations,/media`.

Administration (P3, P4 et P6) : ajouter `/admin/auth,/admin/sellers,/admin/customers,/admin/search,/admin/dashboard,/admin/products,/admin/categories,/admin/looks,/admin/media,/admin/reservations`. Les écrans de réglages restent simulés et ne reconnaissent pas le vrai jeton admin : ils afficheront une erreur jusqu’à la phase P8.

- `VITE_DEV_API_PROXY` relaie `/api` du serveur Vite vers cette API : même domaine, comme en production, indispensable au cookie de session.
- **Bascule progressive** : les routes listées dans `VITE_API_REAL_ROUTES` partent vers l’API, le reste reste simulé (tant que `/reservations` est simulé, les mocks montrent les réservations de démonstration).
- **Tout réel** : `VITE_API_MOCK=false` (les écrans de réglages de l’administration attendent la phase P8).

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
