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
# Créer .env.local (jamais versionné) avec : APP_SECRET (php -r "echo bin2hex(random_bytes(32));"), DATABASE_URL,
# JWT_PASSPHRASE, CLOUDINARY_URL, TWILIO_*, ADMIN_PHONE_NUMBER, PAWAPAY_* (PAWAPAY_ENABLED=0) — voir la liste dans .env
# Copier les clés JWT dans config/jwt/private.pem et config/jwt/public.pem (jamais versionnées)
php bin/console doctrine:migrations:migrate
php bin/console app:seed                # données de départ : catalogue, looks, villes, agences, pages, réglages
php bin/console app:create-owner        # compte propriétaire (mot de passe demandé, masqué)
php bin/console app:create-admin        # gérant ou vendeur (Yaoundé / Douala)
php bin/console app:diagnostic          # vérifie base, JWT, Cloudinary, Twilio, codes SMS, PawaPay
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public
```

L’API répond sous `http://127.0.0.1:8000/api` (ex. `GET /api/health`). En développement (`APP_ENV=dev`), elle ne répond qu’à cet ordinateur et au réseau local ; pour un nom particulier (tunnel d’essai), `APP_DEV_HOSTS=nom1,nom2`. **Mise en ligne : voir « Production » plus bas.**

## Données de départ (`app:seed`)

Les fichiers `data/seed/*.json` reprennent les données de démonstration du front (24 articles répartis entre Yaoundé et Douala, 3 catégories, 4 looks, 15 villes, 9 pages, réglages de paiement USSD), sauf deux fichiers qui contiennent maintenant des **données réelles** : `agencies.json` (agences d’expédition et leurs prix publiés ; `"isActive": false` = agence enregistrée mais cachée aux clients tant que son prix n’est pas connu) et `settings.json` (boutique de Yaoundé, numéro de la boutique). Ne pas les régénérer depuis les mocks du front.

- À la réservation (`/api/delivery/cities?from=`), seules les villes où le colis peut arriver sont proposées : celle de la boutique (livraison à domicile) et celles qui ont au moins une agence active au départ de cette boutique. Sans `from` (profil, adresses), toutes les villes.

- `php bin/console app:seed` : ajoute ce qui manque, sans rien écraser ni supprimer (sans danger à relancer).
- `--only=pages,settings` : seulement certaines parties (`categories`, `products`, `looks`, `delivery`, `pages`, `settings`).
- `--update` : reprend les valeurs du fichier pour ce qui existe déjà (confirmation demandée).
- **En production** : `app:seed` pose une question et ne charge que `categories`, `delivery`, `pages` et `settings`. Les articles et les looks du fichier sont fictifs : ne jamais les charger en ligne (il faudrait les demander avec `--only`).
- Pour régénérer les autres fichiers depuis les mocks du front : voir `tools/export-front-fixtures.ts` (sans `agencies.json` ni `settings.json`).

## Connexion des clients par code SMS (P2)

Le client se connecte avec son numéro : un code à 6 chiffres part par SMS (Twilio), valable 5 min.

| Réglage (`.env.local`) | Rôle |
|---|---|
| `OTP_SMS_TRANSPORT=twilio` | Envoi réel par Twilio (par défaut). |
| `OTP_SMS_TRANSPORT=log` | Développement seulement : aucun SMS, le code est écrit dans `var/log/dev.log` (ignoré en production). |
| `OTP_WEBOTP_HOST=drchoco.com` | Domaine de l’app : le SMS se termine par `@drchoco.com #123456` (remplissage automatique sur Android). |
| `OTP_DAILY_SMS_LIMIT=300` | Plafond de SMS sur 24 h pour toute la boutique (protection des coûts). Un tiers est réservé aux numéros qui ont déjà un compte. |

- **Compte Twilio d’essai** (actuel) : les SMS ne partent que vers les numéros vérifiés dans la console Twilio (*Phone Numbers > Verified Caller IDs*), précédés de « Sent from your Twilio trial account ». Pour tester avec un autre numéro, utiliser `OTP_SMS_TRANSPORT=log`. Avec le compte payant, il suffit de remplacer `TWILIO_*` dans `.env.local`.
- `php bin/console app:sms:test 690000001` : envoie un SMS d’essai (confirmation demandée).
- SMS non parti : le client voit « Impossible d’envoyer le SMS pour le moment » ; `var/log/prod.log` contient « Code de connexion non envoyé… » (numéro masqué) et la raison exacte donnée par Twilio est dans le journal PHP (`error_log` de l’hébergement, jamais servi au public).
- Limites : renvoi après 60 s, 3 demandes en 10 min et 10 par jour par numéro, 30 SMS par heure par adresse IP (une demande sans SMS ne compte pas) ; 5 codes faux bloquent le numéro 10 min.
- Session du client : cookie httpOnly `drc_refresh` (90 jours glissants). Il n’est posé que si l’app et l’API sont sur le **même domaine**.

## Connexion des clients par WhatsApp (après P9, par défaut)

Le code à 6 chiffres arrive **sur WhatsApp** au lieu du SMS : le client appuie sur « Recevoir mon code sur WhatsApp », WhatsApp s'ouvre avec un message déjà écrit qui contient une **référence** (« Je veux recevoir mon code de connexion DR Choco. Réf. K7F2QX ») ; il l'envoie, la boutique répond avec le code, qu'il saisit dans l'app comme un code SMS (`POST /api/auth/otp/verify`). Gratuit : c'est le client qui écrit en premier. **Le code n'est envoyé que si le message vient du WhatsApp du numéro saisi** : la référence seule ne prouve pas que l'on possède le numéro. Le SMS reste en secours. Détails : `WHATSAPP_OTP.md`.

| Réglage | Où | Rôle |
|---|---|---|
| `WHATSAPP_BUSINESS_NUMBER`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_GRAPH_VERSION`, `WHATSAPP_GRAPH_URL` | `.env` (déjà remplis) | Numéro +237 6 59 88 16 83, identifiants publics de l'app Meta « Dr Choco OTP », adresse de l'API de Meta |
| `WHATSAPP_APP_SECRET` | `.env.local` | Clé secrète de l'app Meta : authentifie chaque message transmis |
| `WHATSAPP_VERIFY_TOKEN` | `.env.local` | Chaîne inventée, recopiée dans la console Meta (adresse de rappel) |
| `WHATSAPP_TOKEN` | `.env.local` | Jeton permanent de l'utilisateur système : **envoie le code au client** |

Sans `WHATSAPP_APP_SECRET` ou `WHATSAPP_TOKEN`, `GET /api/settings/public` répond `whatsappLogin: null` et l'app ne propose que le SMS.

- Routes : `POST /api/auth/whatsapp/start` (app : numéro et référence), `GET` et `POST /api/webhooks/whatsapp` (Meta). Adresse de rappel à donner à Meta : `https://<api>/api/webhooks/whatsapp`, champ `messages`.
- Une demande vaut 10 min et ne donne qu'un code (valable 5 min, mêmes limites que le SMS pour le numéro, hors plafond quotidien de SMS). Message venu d'un autre WhatsApp : une explication, aucun code. Même message transmis deux fois par Meta : traité une fois. Réponses (code, explication) envoyées après la réponse faite à Meta ; une même explication une fois par heure au plus.
- Limites des demandes : 5 en 10 min et 20 par jour par numéro, 30 en 10 min par adresse IP.
- `php bin/console app:diagnostic` lit le numéro chez Meta avec le jeton (aucun message envoyé).
- Essai sur le poste de développement : un tunnel HTTPS (`ngrok http 8000`), son nom dans `APP_DEV_HOSTS` (le mode développement est fermé aux autres adresses), son adresse dans la console Meta.

### Tâche planifiée (cPanel)

Chaque heure : `php bin/console app:purge` (codes SMS et demandes WhatsApp de plus de 48 h, sessions finies, fichiers de verrou de `var/lock` inutilisés depuis 2 h, vieilles notifications, captures et photos de plus de 12 mois).

## Administration : comptes de l’équipe (P3)

Trois rôles : **owner** (propriétaire) et **manager** (gérant) sont super admins ; **seller** (vendeur) est rattaché à Yaoundé ou à Douala et ne voit que sa ville (ses articles et ceux de sa ville sans vendeur). Les droits sont contrôlés par le serveur, pas seulement par le menu du front.

- Premier compte : `php bin/console app:create-owner` (mot de passe masqué, 10 caractères minimum).
- Vendeurs : depuis l’administration, écran « Vendeurs (Villes) » (création, ville, activation), ou `php bin/console app:create-admin --role=seller --city=Douala`. Gérant : `app:create-admin --role=manager`.
- Connexion avec l’e-mail ou le téléphone ; 5 mots de passe faux bloquent l’identifiant 15 min. Session admin séparée de celle des clients (cookie `drc_admin_refresh`, 12 h au plus).
- Mots de passe : 10 caractères au moins, ni mot de passe connu, nom de la boutique ou de la ville, suite de touches, chiffres seuls (même suivis d’une année ou de symboles), ni l’e-mail ou le téléphone du compte. Chacun change le sien dans l’administration (l’ancien est demandé, les autres sessions sont coupées).
- Un vendeur désactivé, ou dont le mot de passe change, perd l’accès immédiatement (sessions, jeton en cours et notifications coupés). Un vendeur qui change de ville emmène ses articles. Suppression refusée tant qu’il a des articles : le désactiver.
- Dépannage de n’importe quel compte (propriétaire compris) : `php bin/console app:admin:update <e-mail ou téléphone> --password | --disable | --enable`. Le dernier propriétaire actif ne peut pas être désactivé.
- Compte bloqué après 5 mots de passe faux (ou 20 depuis une même adresse IP) : attendre 15 min, ou `php bin/console cache:pool:clear cache.rate_limiter`. Un inconnu ne peut pas bloquer l’appareil d’un membre de l’équipe : chaque appareil déjà connecté a son propre compteur.

## Catalogue de l’administration et images (P4)

- Articles : création, modification, stock rapide et suppression depuis l’écran « Catalogue ». Un vendeur gère ses articles et ceux de sa ville sans vendeur ; ce qu’il crée lui est rattaché, dans sa ville. Le super admin choisit la ville (et peut rattacher un vendeur).
- Les variantes (couleur × taille) gardent leur identifiant d’un enregistrement à l’autre : paniers et réservations restent valables.
- Supprimer un article le retire de la vente partout (site, looks, recherche) ; son historique est gardé et son adresse redevient libre.
- Catégories, sous-catégories et looks : écran « Looks & Catégories ». Retirer une sous-catégorie laisse ses articles dans la catégorie.
- Photos d’articles, de catégories et de looks : seulement celles importées sur Cloudinary, ou un chemin du site (`/images/…`).
- Images : `POST /api/admin/media` (JPEG, PNG ou WebP, 5 Mo au plus ; 60 en 10 min et 300 par jour et par compte) les envoie sur Cloudinary, dossiers `drchoco/products`, `drchoco/categories`, `drchoco/looks`. Le front y envoie les photos choisies dans l’administration. Sur l’hébergement, `upload_max_filesize` et `post_max_size` (PHP) doivent dépasser 5 Mo.

## Réservations et paiement Mobile Money (P5)

- Une réservation ne contient que des articles d’**une seule ville** (Yaoundé ou Douala) ; sinon `422 MIXED_CITIES`.
- **Retrait** à la boutique de cette ville (gratuit). **Livraison** : à domicile dans la ville de la boutique (prix `homeDeliveryFee` de la boutique), sinon par une **agence** choisie par le client, au prix de cette agence (champ `fee` de l’agence ; vide = prix de sa ville). Une agence peut être réservée à une boutique de départ (`departure_city`). Les boutiques (adresse, horaires, prix de livraison à domicile) sont dans les réglages publics (`shops`).
- Le stock est retiré à la réservation, sous verrou (deux clients ne peuvent pas prendre la dernière pièce), et remis en vente une seule fois si la réservation expire ou est annulée.
- Délai de paiement : `reservationHoldHours` (24 h). Une réservation « en attente de validation » (preuve envoyée) n’expire jamais.
- Limites par client (P9) : 10 pièces par article et 30 par réservation, 5 réservations ouvertes (non payées ou à vérifier) et 60 pièces en tout, 10 réservations par heure et 30 par jour. Le montant confirmé par le client (`expectedTotal`) doit être encore juste, sinon `409 PRICE_CHANGED` et rien n’est réservé.
- Preuves de paiement : `POST /api/reservations/{ref}/proof` (JPEG, PNG ou WebP, 10 Mo, 12 000 pixels de côté et 40 mégapixels au plus ; 20 envois par heure et 15 captures acceptées par jour et par client). Rangées **hors du site public** dans `var/private/proofs/`, sans leurs métadonnées (GPS, appareil, images cachées), servies par des liens signés valables 1 h (`/api/media/private/proofs/{id}`). Une même image ne peut pas payer deux réservations, même légèrement modifiée (métadonnées). Effacées après 12 mois (empreinte gardée). À sauvegarder avec la base. Sur l’hébergement, `upload_max_filesize` doit dépasser 11 Mo et `post_max_size` 31 Mo (3 photos du colis).
- Routes client : `POST /api/reservations` (en-tête `Idempotency-Key`), `GET /api/reservations?status=reserved`, `GET /api/reservations?scope=active|done&page=`, `GET /api/reservations/summary`, `GET /api/reservations/{ref}`, `POST /api/reservations/{ref}/proof`, `POST /api/reservations/{ref}/cancel`. La réservation d’un autre client répond 404.
- La validation des paiements et les étapes de la boutique sont décrites ci-dessous (P6) ; les notifications push arrivent en P7 (en attendant, nouvelles réservations, preuves reçues et rappels sont écrits dans `var/log`).

### Tâches planifiées des réservations (cPanel)

- Toutes les 5 minutes : `php bin/console app:reservations:expire` (réservations non payées à l’échéance → expirées, articles remis en vente).
- Toutes les 15 minutes : `php bin/console app:reservations:remind` (rappel, une seule fois, quand il reste moins de 2 h).

## Gestion des réservations par l’équipe (P6)

- **Qui fait quoi** : le super admin (propriétaire, gérant) voit tout, **valide ou rejette les paiements** et **annule** ; un vendeur voit seulement les réservations qui contiennent ses articles (ou des articles sans vendeur de sa ville), prépare, photographie le colis, le remet et ajoute des notes. Hors de son périmètre : 404 ; paiement ou annulation : 403.
- **Parcours** : retrait = payée → en préparation → prête en boutique (photos) → retirée ; livraison = payée → en préparation → remise à l’agence ou au livreur (photos) → en route → livrée. Le serveur refuse toute autre étape (`422 INVALID_STATUS`, `INVALID_RECEPTION_MODE`, `409 ALREADY_FINAL`). Un double clic ne crée pas de seconde étape.
- **Preuve affichée = preuve jugée** (P9) : l’écran envoie le statut et l’empreinte de la capture affichée ; si un collègue a déjà répondu ou si le client a renvoyé une capture, `409 STATE_CHANGED` et rien n’est fait. Une transaction déjà déclarée pour une autre réservation est signalée. La capture, le numéro payeur et la transaction ne sont montrés qu’aux super admins.
- **Remboursement à prévoir** : une réservation annulée après un paiement validé, ou avec une preuve jamais examinée, est signalée (`refundDue`) dans les listes, sur la fiche et par notification, jusqu’à ce qu’un super admin la close (« Remboursement fait », avec une explication gardée dans les notes internes).
- **Paiement rejeté** : le motif est montré au client, qui a **au moins 6 h** pour renvoyer une preuve (l’échéance d’origine n’est jamais raccourcie). Validation « manuelle » sans preuve possible tant que l’échéance n’est pas passée.
- **Photos du colis** : 1 à 3 photos (JPEG, PNG ou WebP, 10 Mo chacune) envoyées en `multipart` (`photos[]`), rangées hors du site public dans `var/private/packages/`, sans métadonnées, montrées à l’équipe et au client par des liens signés valables 1 h (`/api/media/private/package-photos/{id}`). À sauvegarder avec la base.
- **Annulation par la boutique** : articles remis en vente tout de suite ; le motif est affiché au client. Remboursement d’un paiement déjà validé : par Mobile Money, hors de l’application.
- Notes internes : jamais montrées au client.
- Routes : `GET /api/admin/reservations` (onglets `tab`, `status`, `receptionMode`, `city`, `shopCity`, `operator`, `q`, `page`, `pageSize`), `GET /api/admin/reservations/{ref}`, `POST …/{ref}/validate-payment`, `…/reject-payment`, `…/status`, `…/package-ready`, `…/cancel`, `…/notes`. La recherche rapide, la liste des clients (nombre de réservations, total payé) et les compteurs du tableau de bord utilisent maintenant les réservations.

## Notifications push et chiffres de ventes (P7)

- **Qui reçoit quoi** :
  - l’équipe : nouvelle réservation (super admins et vendeurs concernés), preuve de paiement à vérifier (super admins), annulation par un client qui avait déjà payé (super admins) ;
  - le client : paiement validé ou rejeté (avec le motif et la nouvelle heure limite), colis prêt (avec la photo du colis), en route, livré ou retiré, réservation bientôt expirée (2 h avant), annulée par la boutique.
- **Abonnement des appareils** : `POST /api/push/subscribe` et `/api/push/unsubscribe` (client ; le service worker peut renouveler l’abonnement avec le seul cookie de session), `POST /api/admin/push/subscribe` et `/api/admin/push/unsubscribe` (équipe). Seuls les services de push des navigateurs sont acceptés ; 10 appareils au plus par compte.
- **Envoi** : chaque notification passe par une file d’attente en base, envoyée juste après la réponse, puis par la tâche `app:push:send` (nouveaux essais en cas d’erreur passagère ; appareil désabonné supprimé). Une panne d’envoi n’annule jamais une action.
- **Clés VAPID** (une seule fois par site : en changer désabonne tous les appareils) :
  ```bash
  php bin/console app:push:vapid-keys --write=.env.local
  ```
  La clé privée reste dans `.env.local` ; la clé publique affichée va dans le front : `VITE_VAPID_PUBLIC_KEY` (au moment de construire le front). `VAPID_SUBJECT` : `mailto:` de la boutique. `PUSH_TRANSPORT=log` (développement seulement, ignoré en production) : aucune notification envoyée, écrites dans le journal.
- **Windows / XAMPP** : PHP ne trouve pas `openssl.cnf`, et le chiffrement des notifications échoue. Définir la variable d’environnement `OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf` (variable Windows de l’utilisateur, puis rouvrir le terminal). Inutile sur cPanel (Linux). `php bin/console app:diagnostic` l’indique.
- **Essai** : `php bin/console app:push:test 690000001` (client) ou `app:push:test proprietaire@drchoco.cm` (équipe).
- **Chiffres de ventes** (tableau de bord et liste des vendeurs) : ventes = paiements validés, sauf réservations annulées ensuite ; chiffre d’affaires hors frais de livraison ; périodes en heure du Cameroun (aujourd’hui depuis minuit, 7, 30 et 365 jours) ; un vendeur ne voit que les ventes de ses articles.

### Tâches planifiées (cPanel)

- Chaque minute : `php bin/console app:push:send` (notifications en attente).
- Chaque heure : `php bin/console app:purge` (codes SMS, sessions, verrous, notifications de plus de 7 jours, appareils inactifs depuis 90 jours, appareils de l’équipe inutilisés depuis 30 jours, images de plus de 12 mois).

## Réglages, livraison et pages dans l’administration (P8)

Réservé au super admin (propriétaire, gérant) ; un vendeur reçoit 403. Chaque modification est visible tout de suite par les clients.

- **Réglages** (`GET`/`PATCH /api/admin/settings`, modification partielle, rien n’est enregistré si un champ est invalide) : opérateurs Mobile Money (nom et code marchand — chiffres seulement s’il est composé dans le code USSD —, code USSD qui commence par `*` ou `#`, finit par `#` et contient `{code}` et `{amount}`, image du QR code, étapes, actif ; au moins un actif ; un opérateur désactivé n’est plus proposé, mais une preuve déjà payée par lui reste acceptée), délai de paiement (1 à 72 h), taille maximale d’une capture (1 à 25 Mo, et jamais plus que la limite d’envoi de PHP), paiement des frais de livraison, numéro WhatsApp, **une boutique par ville** (`shops` : nom, adresse, horaires, lien Maps, prix de la livraison à domicile). Les changements de codes marchands sont écrits dans le journal d’audit (avant / après, auteur) ; un nouveau code marchand ou USSD sans nouvelle image **retire l’ancien QR code** (il enverrait encore l’argent à l’ancien compte) et met à jour les étapes qui le citent ; deux enregistrements simultanés ne s’écrasent pas.
- **Villes de livraison** (`/api/admin/delivery/cities`) : ajout, modification, suppression. Yaoundé et Douala (villes des boutiques) ne peuvent être ni renommées, ni masquées, ni supprimées ; une ville qui a encore des agences n’est pas supprimée (409). Une ville renommée l’est aussi dans les adresses et les profils des clients.
- **Agences** (`/api/admin/delivery/agencies`) : ajout, modification, suppression ; prix propre (vide = prix de la ville), boutique de départ (Yaoundé, Douala ou les deux), proposée aux clients ou masquée. Une agence masquée ou sans agence active : la ville n’est pas proposée à la réservation.
- **Pages** (`/api/admin/pages`) : les 9 pages légales, d’aide et la FAQ, en Markdown, publiées dès l’enregistrement ; les passages « [À COMPLÉTER …] » restants sont comptés ; les espaces insécables du français sont ajoutées.
- Textes affichés (horaires, adresses, délais, étapes) : espaces insécables du français ajoutées, comme dans les pages.
- Carnet d’adresses du client : à Yaoundé et à Douala, livraison à domicile ou par agence (selon l’agence choisie) ; ailleurs, selon la ville.

## Référencement et partage (P9)

- `https://drchoco.com/produit/{slug}` est servi par l’API : la page du front (`index.html`) avec, dans son en-tête, le titre, la description, le prix, la photo et l’adresse de l’article. WhatsApp, Facebook et Google voient donc la bonne fiche ; un article inconnu ou retiré répond 404 (avec la page du front). Le visiteur, lui, garde la même application.
- Plans du site calculés à chaque lecture : `/sitemap-index.xml` → `/sitemap.xml` (pages fixes, fichier du front), `/sitemap-categories.xml`, `/sitemap-produits.xml`.
- `SITE_URL` (`https://drchoco.com`) : toutes les adresses publiques en viennent, jamais de la requête. `FRONT_INDEX_PATH` : vide sur l’hébergement (l’`index.html` de la racine du site est lu) ; en développement, chemin complet de `dist/index.html`.

## Sécurité (P9)

- **Journal d’audit** : `var/log/audit-AAAA-MM-JJ.log`, une ligne JSON par action sensible (connexions de l’équipe et refus, codes marchands avant / après, comptes, prix, stocks, suppressions, paiements validés ou rejetés, annulations, clients bloqués ou effacés, numéros verrouillés). 400 jours, sans donnée de client en clair.
- **Clients** : `php bin/console app:customer <téléphone>` avec `--block [--reason=…]`, `--unblock`, `--close-sessions` (téléphone volé), `--export=fichier.json` (droit d’accès) ou `--erase` (droit à l’effacement : compte et adresses supprimés, réservations rendues anonymes, montants gardés ; refusé tant qu’une réservation est en cours ou qu’un remboursement est à prévoir). Blocage aussi dans l’administration (écran « Clients », super admin) : sessions et notifications coupées aussitôt.
- **Catégories** : 30 au plus ; une catégorie sans article en vente peut être retirée par un super admin.
- **Disque plein** : aucune image n’est enregistrée sous 200 Mo libres (`507 STORAGE_FULL`), et la connexion de l’équipe est refusée si les compteurs d’essais ne peuvent plus être écrits (jamais d’essais sans limite).
- **Corps des requêtes** : 64 Ko (1 Mo pour les formulaires longs de l’administration), 120 écritures par minute et par membre de l’équipe, 60 par client.
- Le mode développement refuse tout visiteur extérieur ; la production refuse de démarrer sans `APP_SECRET` solide.

## Production

### Réglages (`.env.local` sur le serveur, droits 600)

| Variable | Valeur |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `prod`, `0` |
| `APP_SECRET` | une nouvelle valeur : `php -r "echo bin2hex(random_bytes(32));"` (jamais celle d’un autre projet) |
| `DATABASE_URL` | base du site, avec la vraie version du serveur (`serverVersion=…`, donnée par `SELECT VERSION()`) |
| `JWT_PASSPHRASE` + `config/jwt/*.pem` | mêmes clés et même phrase qu’aujourd’hui |
| `CORS_ALLOW_ORIGIN` | `'^https://drchoco\.com$'` |
| `SITE_URL`, `DEFAULT_URI` | `https://drchoco.com` |
| `OTP_WEBOTP_HOST` | `drchoco.com` |
| `CLOUDINARY_URL`, `TWILIO_*`, `ADMIN_PHONE_NUMBER` | comptes réels |
| `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_TOKEN` | connexion par WhatsApp (section plus haut) |
| `VAPID_*` | `php bin/console app:push:vapid-keys --write=.env.local`, puis `VAPID_SUBJECT=mailto:…` |
| `SYMFONY_TRUSTED_PROXIES`, `SYMFONY_TRUSTED_HEADERS` | **seulement** si un relais (Cloudflare, nginx) est devant PHP et que l’hébergeur ne rétablit pas l’adresse du visiteur : plages du relais (jamais `0.0.0.0/0`) et `x-forwarded-for,x-forwarded-proto`. Sinon toutes les limites par adresse IP visent le relais |
| `PUSH_EXTRA_HOSTS`, `APP_DEV_HOSTS`, `OTP_SMS_TRANSPORT`, `PUSH_TRANSPORT` | absentes |

### Installer, puis mettre à jour

```bash
cd ~/dr_choco_back                                  # hors de public_html ; seul public/ est exposé (lien public_html/_api)
composer install --no-dev -o
php bin/console doctrine:migrations:migrate -n
php bin/console cache:clear --env=prod              # sans cette ligne, chaque page répond 500 après une mise à jour
php bin/console app:diagnostic --production         # échoue si un réglage manque
chmod 711 . && chmod 755 public && chmod 700 config var src data migrations && chmod 600 .env.local config/jwt/*.pem
```

Première installation seulement, avant le diagnostic : `app:seed` (catégories, livraison, pages, réglages) et `app:create-owner`.

### Tâches planifiées (cPanel)

Remplacer `php` par le PHP de la version choisie (par exemple `/usr/local/bin/ea-php83`), version 8.3 ou plus : PHP 8.2 ne reçoit plus de correctifs après le 31 décembre 2026.

```cron
* * * * *    cd ~/dr_choco_back && php bin/console app:push:send >/dev/null
*/5 * * * *  cd ~/dr_choco_back && php bin/console app:reservations:expire >/dev/null
*/15 * * * * cd ~/dr_choco_back && php bin/console app:reservations:remind >/dev/null
17 * * * *   cd ~/dr_choco_back && php bin/console app:purge >/dev/null
```

Les erreurs restent envoyées par e-mail (seule la sortie normale est supprimée).

### Points de l’hébergement à vérifier

- LiteSpeed au lieu d’Apache : vérifier que `https://drchoco.com/_api/` répond 404 et que `/produit/…` montre la bonne fiche (règles `REDIRECT_STATUS`, `<If>`).
- Un nouveau `public_html/.htaccess` efface les lignes ajoutées par « MultiPHP Manager » : les remettre.
- MariaDB 11.6.2 ou plus : `innodb_snapshot_isolation=OFF` (ou tester deux réservations simultanées).
- `upload_max_filesize` > 11 Mo et `post_max_size` > 31 Mo.
- Sauvegardes : base **et** `var/private`.

## Brancher le front sur cette API

Dans le front (`.env.local`) :

```env
VITE_API_MOCK=true
VITE_API_BASE_URL=/api
VITE_DEV_API_PROXY=http://127.0.0.1:8000
VITE_API_REAL_ROUTES=/categories,/products,/search,/looks,/settings/public,/delivery,/pages,/cart/validate,/client-errors,/auth,/me,/favorites
```

Réservations (P5) : ajouter `/reservations,/media`.

Administration (P3, P4 et P6) : ajouter `/admin/auth,/admin/sellers,/admin/customers,/admin/search,/admin/dashboard,/admin/products,/admin/categories,/admin/looks,/admin/media,/admin/reservations`.

Notifications push (P7) : ajouter `/push,/admin/push`, construire le front avec `VITE_PWA_ENABLED=true` et `VITE_VAPID_PUBLIC_KEY` = la clé publique du serveur.

Réglages, livraison et pages (P8) : ajouter `/admin/settings,/admin/delivery,/admin/pages`.

- `VITE_DEV_API_PROXY` relaie `/api` du serveur Vite vers cette API : même domaine, comme en production, indispensable au cookie de session.
- **Bascule progressive** : les routes listées dans `VITE_API_REAL_ROUTES` partent vers l’API, le reste reste simulé (tant que `/reservations` est simulé, les mocks montrent les réservations de démonstration).
- **Tout réel** : `VITE_API_MOCK=false` (tous les écrans utilisent alors l’API).

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
