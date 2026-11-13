# Plan de construction — API DR Choco L’Incompris

Avancement total : **8 %** (P0 terminée)

| Phase | Contenu | Routes | Poids | État |
|---|---|---|---|---|
| **P0** | Mise en place du projet (socle) | 1 | 8 % | ✅ Terminée |
| **P1** | Catalogue public et référentiels | 11 | 12 % | ⏳ À faire |
| **P2** | Comptes clients (code SMS) | 11 | 10 % | ⏳ À faire |
| **P3** | Socle admin, rôles et vendeurs | 10 | 8 % | ⏳ À faire |
| **P4** | Catalogue admin et images Cloudinary | 14 | 12 % | ⏳ À faire |
| **P5** | Réservations et paiement USSD | 9 + 2 crons | 15 % | ⏳ À faire |
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

## Points ouverts (à décider)

| Avant | Question | Proposition |
|---|---|---|
| P2 | Compte Twilio **d’essai** (expéditeur +1) : SMS seulement vers des numéros vérifiés | Passer en compte payant avant la mise en ligne ; vérifier les numéros de test en attendant |
| P5 | Panier limité à une seule ville ? | Oui : un panier = une ville |
| P5 | Retrait en boutique possible à Douala ? | Une boutique par ville (table dédiée) |
| P5 | Qui valide les paiements Mobile Money ? | Le super admin (comptes marchands DR Choco) |
| P4 | `CloudinaryService::deleteProductFolder()` (hérité tel quel) appelle une méthode qui n'existe pas dans le SDK (`deleteResourcesByPrefix` au lieu de `deleteAssetsByPrefix`) : plantage si on l'utilise | Corriger cette seule ligne en P4 (accord demandé, car Cloudinary est repris à l'identique) |
| P10 | Offre cPanel (PHP, base, SSH, crons) et domaine (.cm ou .com) | — |
| P10 | Montage sur cPanel : les routes gardent le préfixe `/api` | Projet hors de `public_html`, son dossier `public/` exposé sous `public_html/_api` (lien), et règle du `.htaccess` du front : `^api(/.*)?$ → _api/index.php` |
