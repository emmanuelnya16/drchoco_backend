# Plan de construction — API DR Choco L’Incompris

Avancement total : **30 %** (P0, P1 et P2 terminées)

| Phase | Contenu | Routes | Poids | État |
|---|---|---|---|---|
| **P0** | Mise en place du projet (socle) | 1 | 8 % | ✅ Terminée |
| **P1** | Catalogue public et référentiels | 11 + 1 | 12 % | ✅ Terminée |
| **P2** | Comptes clients (code SMS) | 11 + 2 | 10 % | ✅ Terminée |
| **P3** | Socle admin, rôles et vendeurs | 10 | 8 % | ⏳ À faire |
| **P4** | Catalogue admin et images Cloudinary | 14 | 12 % | ⏳ À faire |
| **P5** | Réservations et paiement USSD | 8 + 2 crons | 15 % | ⏳ À faire |
| **P6** | Gestion des réservations (admin) | 10 | 10 % | ⏳ À faire |
| **P7** | Notifications push et tableau de bord | 5 + déclencheurs | 8 % | ⏳ À faire |
| **P8** | Réglages, livraison et pages (admin) | 10 | 6 % | ⏳ À faire |
| **P9** | SEO, sécurité, tests de bout en bout | — | 6 % | ⏳ À faire |
| **P10** | Mise en ligne sur cPanel | — | 5 % | ⏳ À faire |

---

## P0 — Mise en place ✅

- [x] Projet Symfony 7.4 LTS (profil API, sans Twig/Turbo/Stimulus)
- [x] JWT repris **à l’identique** du backend de référence : même configuration lexik (RS256, 1 h, Bearer), **mêmes clés** (lexik 3.2 : extension PHP `sodium` activée dans XAMPP, sauvegarde `php.ini.bak-avant-sodium`)
- [x] Cloudinary repris **à l’identique** (même compte, même service) — seul le dossier change : `drchoco/products`
- [x] Twilio (SMS) repris : `send()` et normalisation +237
- [x] PawaPay repris mais **désactivé** (`PAWAPAY_ENABLED=0`)
- [x] Sessions **séparées** : client 90 jours glissants (`drc_refresh`, Path=/api) ; admin 12 h maximum (`drc_admin_refresh`, Path=/api/admin/auth, SameSite=Strict)
- [x] Rotation des jetons avec délai de grâce de 30 s et révocation de la famille en cas de réutilisation
- [x] Client bloqué / vendeur désactivé : accès coupé immédiatement
- [x] Erreurs au format du contrat `{code, message, details}` en français (404, 405, 401, 403, 422, 429, 500…)
- [x] Routes publiques : un jeton expiré ou invalide est ignoré (contrat §2.4.5)
- [x] Rôles : owner, manager (super admins), seller (vendeur rattaché à une ville)
- [x] Dates en UTC, tri insensible aux accents (`utf8mb4_unicode_ci`), langue française
- [x] `.htaccess` Apache (cPanel) avec transmission de l’en-tête Authorization
- [x] Base `drchoco_db` : tables `users`, `admins`, `refresh_tokens` (1 migration)
- [x] `GET /api/health`, commandes `app:create-owner` et `app:diagnostic`
- [x] Relecture adverse (3 relecteurs + contre-vérification) : 1 bloquant et 5 majeurs corrigés
  - jetons marqués « drchoco » : un jeton émis par le backend de référence (mêmes clés) est refusé
  - jeton client sur /api/admin (ou l'inverse) : 403 ; 401 admin = `ADMIN_UNAUTHORIZED`
  - le JWT admin ne dépasse jamais la fin de session (12 h)
  - une panne pendant le rafraîchissement ne déconnecte plus le client
  - `symfony/mime` ajouté (contrôle du type des images avant envoi Cloudinary)
  - erreurs métier journalisées en « info » (pas d'alerte de panne)
- [x] 28 tests automatiques au vert (aussi lancés fichier par fichier)

**Vérifier soi-même :** `php bin/console app:diagnostic` puis `php bin/phpunit`.

## P1 — Catalogue public et référentiels ✅

- [x] Tables : `categories`, `sub_categories`, `products`, `product_variants`, `looks`, `delivery_cities`, `agencies`, `content_pages`, `app_settings` (1 migration)
- [x] Chaque article porte sa **ville** (Yaoundé ou Douala) et son **vendeur** (rempli à partir de P4)
- [x] `GET /api/categories` : nombres d'articles calculés en direct
- [x] `GET /api/products` : filtres ville (sans accents ni casse), catégorie, sous-catégorie, tailles répétées, tranches de prix, stock, look, recherche ; tris ; pagination ; facettes calculées sur la ville et la catégorie
- [x] `GET /api/products/{slug}` : fiche complète + 6 articles associés (même sous-catégorie, puis même catégorie, même ville en premier) ; 404 `NOT_FOUND`
- [x] `GET /api/search/suggest` : même recherche que la liste, filtre de ville
- [x] `GET /api/looks`, `GET /api/looks/{id}` : articles retirés ignorés
- [x] `GET /api/settings/public` : boutique de Yaoundé, codes USSD Orange Money et MTN MoMo réels ; ne tombe jamais (valeurs de départ si rien n'est enregistré)
- [x] `GET /api/delivery/cities`, `GET /api/delivery/agencies?city=` (nom sans accents ni casse, ou identifiant)
- [x] `GET /api/pages/{slug}` : 404 `PAGE_NOT_FOUND` avec `details.slug`
- [x] `POST /api/client-errors` : journal dédié de 30 jours, sans donnée personnelle, 20 envois par heure et par adresse IP
- [x] **En plus** : `POST /api/cart/validate` (prévu en P5, avancé car le panier l'appelle dès maintenant)
- [x] Cache : `public, max-age=60, stale-while-revalidate=600` + ETag (304), pages `max-age=300` ; écritures `no-store`
- [x] Articles retirés de la vente ou catégorie masquée : invisibles partout (liste, fiche, looks, compteurs, panier)
- [x] `php bin/console app:seed` : données de départ identiques aux mocks du front (sans danger à relancer)
- [x] Vérifications :
  - 33 tests automatiques du catalogue (63 au total), aussi dans le désordre et fichier par fichier ;
  - réponses réelles validées par les schémas de contrôle du front (24 fiches, 0 écart) ;
  - 32 combinaisons de filtres comparées aux mocks du front : mêmes articles, même ordre, mêmes facettes ;
  - front compilé et ouvert dans un navigateur automatisé : accueil, explorer (Douala), fiche, pages lus sur la vraie API
- [x] Front : interrupteur `VITE_API_REAL_ROUTES` (bascule route par route, le reste reste simulé)

Écarts volontaires avec les mocks : recherche à plusieurs mots (chaque mot doit être présent, le nom passe en tête) ; articles associés de la même ville d'abord ; vendeur jamais exposé au public.

## P2 — Comptes clients (code SMS) ✅

- [x] Tables : `otp_codes`, `addresses`, `favorites` ; `users.last_login_at` (1 migration)
- [x] `POST /api/auth/otp/request` : code à 6 chiffres par SMS (Twilio, service repris à l’identique), jamais stocké en clair (empreinte HMAC), valable 5 min
  - renvoi après 60 s (une nouvelle demande plus tôt ne renvoie pas de SMS) ; un nouveau code annule le précédent ;
  - 3 demandes en 10 min et 10 par jour par numéro ; 30 par heure par adresse IP (réseau /64 en IPv6) ;
  - plafond de SMS par jour pour toute la boutique (`OTP_DAILY_SMS_LIMIT`, 300) : protection des coûts ;
  - SMS court (alphabet de base, 1 seul SMS), ligne `@<domaine> #code` pour le remplissage automatique sur Android (`OTP_WEBOTP_HOST`)
- [x] `POST /api/auth/otp/verify` : compte créé à la première connexion (`isNewUser`) ; 5 codes faux bloquent le numéro 10 min ; 30 codes faux en 10 min par adresse IP ; session ouverte dans la même transaction (une panne ne fait jamais perdre le code)
- [x] `POST /api/auth/refresh` (rotation, 401 `NO_SESSION` seulement si la session est vraiment refusée) et `POST /api/auth/logout` (204 dans tous les cas, cet appareil seulement)
- [x] `GET /api/me`, `PATCH /api/me` (champs envoyés seulement ; ville = ville desservie, une ville inchangée n’est pas recontrôlée)
- [x] Carnet d’adresses : `GET`, `POST`, `PATCH`, `DELETE /api/me/addresses` — mêmes règles que le formulaire du front, une seule adresse par défaut (même avec deux appareils), pas de doublon au double appui, 20 adresses au plus, adresse d’un autre client = « introuvable »
- [x] Favoris : `POST /api/favorites` (fusion à la connexion) ; **en plus** `GET /api/favorites` et `DELETE /api/favorites/{id}` (prévus par le contrat §16)
- [x] Compte bloqué : ni code, ni connexion, ni rafraîchissement
- [x] Protections : envois forcés depuis un autre site refusés sur `/api/auth/*` (origine contrôlée, JSON obligatoire) ; 60 écritures par minute et par client
- [x] Cache : lectures privées en `private, no-cache` (lisibles hors connexion par le service worker, contrat §17 question 1), écritures en `no-store`
- [x] Commandes : `app:purge` (tâche quotidienne : codes de plus de 48 h, sessions expirées, vieux fichiers de verrou), `app:sms:test` (SMS d’essai), `app:diagnostic` (état de l’envoi des codes)
- [x] `OTP_SMS_TRANSPORT=log` : en développement, codes écrits dans `var/log/dev.log` au lieu d’un SMS (ignoré en production)
- [x] Front : relais `/api` facultatif `VITE_DEV_API_PROXY` (même domaine en développement, nécessaire au cookie de session) ; routes P2 ajoutées à `VITE_API_REAL_ROUTES`
- [x] Vérifications :
  - 64 nouveaux tests automatiques (127 au total), aussi dans le désordre et fichier par fichier ;
  - relecture adverse (4 relecteurs + contre-vérification) : 13 problèmes confirmés et corrigés (2 importants, 11 mineurs) ; seconde relecture des corrections : 4 problèmes mineurs de plus, corrigés ;
  - front compilé et ouvert dans un navigateur automatisé sur la vraie API : code faux (« Il vous reste 4 essais »), connexion par code, étape « prénom », rechargement (session retrouvée), adresse, favoris fusionnés, déconnexion, reconnexion — tout au vert, aucun écart de contrat signalé par l’app
- [x] Front (non enregistré dans git) : le cache hors connexion des données personnelles est vidé aussi quand la session est refusée et avant chaque connexion (téléphone partagé)

Écarts volontaires avec les mocks : nouvelle demande de code dans les 60 s = pas de nouveau SMS ; code mal formé (pas 6 chiffres) refusé sans consommer d’essai ; compte bloqué : 403 `ACCOUNT_BLOCKED` ; favoris d’articles retirés ignorés.

## Points ouverts (à décider)

| Avant | Question | Proposition |
|---|---|---|
| P10 | Compte Twilio **d’essai** (expéditeur +1) : SMS seulement vers des numéros vérifiés | En attente des clés du compte payant : il suffira de remplacer `TWILIO_*` dans `.env.local` (aucun code à changer). D’ici là : numéros vérifiés dans la console Twilio, ou `OTP_SMS_TRANSPORT=log` en développement |
| P10 | Domaine de l’app pour le remplissage automatique du code sur Android | `OTP_WEBOTP_HOST=<domaine>` dans `.env.local` |
| P5 | Panier limité à une seule ville ? | Oui : un panier = une ville |
| P5 | Retrait en boutique possible à Douala ? | Une boutique par ville (table dédiée) |
| P5 | Qui valide les paiements Mobile Money ? | Le super admin (comptes marchands DR Choco) |
| P4 | `CloudinaryService::deleteProductFolder()` (hérité tel quel) appelle une méthode qui n'existe pas dans le SDK (`deleteResourcesByPrefix` au lieu de `deleteAssetsByPrefix`) : plantage si on l'utilise | Corriger cette seule ligne en P4 (accord demandé, car Cloudinary est repris à l'identique) |
| P8 | Numéro WhatsApp de la boutique (aujourd'hui +237690000000, fictif), vraies villes, frais et agences (aujourd'hui ceux des mocks, agences fictives), textes « [À COMPLÉTER] » des pages légales | À saisir dans l'administration (P8) ou à me transmettre pour les données de départ |
| P10 | Offre cPanel (PHP, base, SSH, crons) et domaine (.cm ou .com) | — |
| P10 | Montage sur cPanel : les routes gardent le préfixe `/api` | Projet hors de `public_html`, son dossier `public/` exposé sous `public_html/_api` (lien), et règle du `.htaccess` du front : `^api(/.*)?$ → _api/index.php` |
