# Plan de construction — API DR Choco L’Incompris

Avancement total : **20 %** (P0 et P1 terminées)

| Phase | Contenu | Routes | Poids | État |
|---|---|---|---|---|
| **P0** | Mise en place du projet (socle) | 1 | 8 % | ✅ Terminée |
| **P1** | Catalogue public et référentiels | 11 + 1 | 12 % | ✅ Terminée |
| **P2** | Comptes clients (code SMS) | 11 | 10 % | ⏳ À faire |
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

## Points ouverts (à décider)

| Avant | Question | Proposition |
|---|---|---|
| P2 | Compte Twilio **d’essai** (expéditeur +1) : SMS seulement vers des numéros vérifiés | Passer en compte payant avant la mise en ligne ; vérifier les numéros de test en attendant |
| P5 | Panier limité à une seule ville ? | Oui : un panier = une ville |
| P5 | Retrait en boutique possible à Douala ? | Une boutique par ville (table dédiée) |
| P5 | Qui valide les paiements Mobile Money ? | Le super admin (comptes marchands DR Choco) |
| P4 | `CloudinaryService::deleteProductFolder()` (hérité tel quel) appelle une méthode qui n'existe pas dans le SDK (`deleteResourcesByPrefix` au lieu de `deleteAssetsByPrefix`) : plantage si on l'utilise | Corriger cette seule ligne en P4 (accord demandé, car Cloudinary est repris à l'identique) |
| P8 | Numéro WhatsApp de la boutique (aujourd'hui +237690000000, fictif), vraies villes, frais et agences (aujourd'hui ceux des mocks, agences fictives), textes « [À COMPLÉTER] » des pages légales | À saisir dans l'administration (P8) ou à me transmettre pour les données de départ |
| P10 | Offre cPanel (PHP, base, SSH, crons) et domaine (.cm ou .com) | — |
| P10 | Montage sur cPanel : les routes gardent le préfixe `/api` | Projet hors de `public_html`, son dossier `public/` exposé sous `public_html/_api` (lien), et règle du `.htaccess` du front : `^api(/.*)?$ → _api/index.php` |
