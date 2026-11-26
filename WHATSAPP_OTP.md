# Connexion par WhatsApp (« OTP inversé ») — DR Choco

> Objectif : remplacer l'envoi payant du code par SMS (Twilio, ~0,32 $/SMS) par une vérification **gratuite**
> où **c'est le client qui envoie le code** à la boutique sur WhatsApp. Le reste de la connexion (création du
> compte, JWT lexik, cookie `drc_refresh` de 90 jours) ne change pas.

---

## 1. Le workflow en une image

```
 Front (app DR Choco)                Backend Symfony                     WhatsApp / Meta
 ─────────────────────               ───────────────                     ───────────────
 1. Saisit son numéro  ──POST /api/auth/whatsapp/start──▶  crée une demande
                                                          code DRC-482913
                       ◀── { requestId, code, waLink } ──

 2. Bouton « Vérifier via WhatsApp »
    ouvre waLink ────────────────────────────────────────────────────▶ WhatsApp s'ouvre,
                                                                      message déjà écrit :
                                                                      « Mon code DR Choco : DRC-482913 »
                                                                      Le client appuie sur Envoyer
                                                                               │
                                                  ◀── POST /api/webhooks/whatsapp ─┘
                                                  (Meta : expéditeur 2376XXXXXXXX + texte)
                                                  → code trouvé + même numéro ⇒ demande CONFIRMÉE
                                                  → (optionnel) répond « ✅ Numéro vérifié »

 3. Interroge toutes les 2-3 s
    ──POST /api/auth/whatsapp/status { requestId }──▶
       pending … pending … confirmed
                       ◀── { accessToken, user, isNewUser } + cookie drc_refresh
    → client connecté (même réponse que /api/auth/otp/verify)
```

Pourquoi c'est sûr : le backend n'envoie **aucun code**. Pour confirmer une demande, il faut écrire **depuis le
WhatsApp du numéro saisi** le code que **seul l'écran du client** a reçu. Un fraudeur qui connaît le numéro d'un
autre ne peut rien en faire sans son téléphone.

Pourquoi c'est gratuit : un message **reçu** par l'API WhatsApp ne coûte rien. La réponse « ✅ Numéro vérifié »
est un *service message* : gratuite jusqu'à 1 000 par mois et par numéro, ~0,004 $ au-delà (Cameroun).

---

## 2. Configuration

### Compte Meta (déjà fait)

| Élément | Valeur |
|---|---|
| App Meta | **Dr Choco OTP** (portefeuille *Dr Choco l'Incompris*) |
| Numéro WhatsApp de la boutique | **+237 6 59 88 16 83** (statut *Enregistré*) |
| ID du numéro (Phone number ID) | `1566791356509169` |
| ID du compte WhatsApp (WABA) | `1193091309714036` |

### Variables d'environnement — dans `.env.local` (jamais dans `.env`, qui est versionné)

```dotenv
WHATSAPP_PHONE_NUMBER_ID=1566791356509169
WHATSAPP_BUSINESS_ACCOUNT_ID=1193091309714036
WHATSAPP_BUSINESS_NUMBER=237659881683          # sans + ni espaces : sert à construire le lien wa.me
WHATSAPP_VERIFY_TOKEN=                         # chaîne aléatoire inventée, ex. php -r "echo bin2hex(random_bytes(24));"
WHATSAPP_APP_SECRET=                           # developers.facebook.com → app → Paramètres de l'app → Général → Clé secrète
WHATSAPP_TOKEN=                                # token PERMANENT de l'utilisateur système (voir ci-dessous)
WHATSAPP_GRAPH_VERSION=v21.0                   # version affichée dans la console Meta
```

Dans `.env`, ajouter les mêmes clés **vides** (documentation du projet), comme pour Twilio.

### Token permanent (`WHATSAPP_TOKEN`)

business.facebook.com → portefeuille **Dr Choco l'Incompris** → Paramètres de l'entreprise →
Utilisateurs → **Utilisateurs système** → Ajouter (rôle Admin) → *Attribuer des éléments* : l'app **Dr Choco OTP**
(contrôle total) et le compte WhatsApp **Dr Choco** → **Générer un token** : app Dr Choco OTP, expiration
**Jamais**, autorisations `whatsapp_business_messaging` + `whatsapp_business_management`.

### Webhook (console Meta → WhatsApp → Configuration, ou Étape 2 « Configurer des webhooks »)

1. **URL de rappel** : `https://<domaine-api>/api/webhooks/whatsapp` (HTTPS obligatoire ; en local : `ngrok http 8000`).
2. **Vérifier le token** : la valeur de `WHATSAPP_VERIFY_TOKEN`.
3. **Vérifier et enregistrer** (Meta appelle la route GET, voir §4.3).
4. Champs du webhook : s'abonner à **`messages`**. Garder « S'abonner aux webhooks » activé sur le compte *Dr Choco*.
5. ⚠️ **Publier l'app** (menu *Publier*, mode Live) : tant qu'elle n'est pas publiée, Meta n'envoie que les
   webhooks de test du tableau de bord, **pas les vrais messages des clients**. La publication demande une URL de
   politique de confidentialité (la page « Confidentialité » du site suffit).

---

## 3. Base de données — nouvelle entité `WhatsappLoginRequest` (table `whatsapp_login_requests`)

Même philosophie que `OtpCode` : une ligne par demande, rien de sensible en clair, purge après 48 h.

| Champ | Type | Rôle |
|---|---|---|
| `id` | int auto | |
| `phone` | string(20) | numéro saisi, normalisé `+2376XXXXXXXX` (`PhoneNumber::normalizeCameroonMobile`) |
| `code` | string(12), index | `DRC-` + 6 chiffres. Unique parmi les demandes en attente (re-tirer en cas de collision) |
| `requestTokenHash` | string(64), unique | HMAC-SHA256 du `requestId` remis au front (le `requestId` lui-même n'est jamais stocké) |
| `status` | string(12) | `pending` · `confirmed` · `consumed` · `expired` · `mismatch` |
| `createdAt` / `expiresAt` | datetime_immutable | validité **10 min** |
| `confirmedAt` | datetime_immutable, null | message WhatsApp reçu et valide |
| `consumedAt` | datetime_immutable, null | session ouverte : la demande ne peut plus servir |
| `waMessageId` | string(128), null, unique | `wamid` du message : Meta peut renvoyer le même webhook plusieurs fois |
| `requestIp` | string(45), null | enquête en cas d'abus, effacée avec la ligne |

Migration : `php bin/console make:migration` puis `doctrine:migrations:migrate`.
Purge : ajouter la table à la commande `app:purge` (lignes de plus de 48 h).

---

## 4. Routes

Toutes sous le préfixe `/api` existant, réponses via `ApiJson::noStore()`, erreurs via `ApiException`.

### 4.1 `POST /api/auth/whatsapp/start` — public

Corps : `{ "phone": "6 59 88 16 83" }`

1. Normaliser le numéro (`PhoneNumber::normalizeCameroonMobile`) → sinon `422 INVALID_PHONE` (même message que l'OTP SMS).
2. Compte bloqué → `403 ACCOUNT_BLOCKED`.
3. Limites (reprendre celles d'`OtpService`) : 3 demandes / 10 min et 10 / jour par numéro, limiter
   `limiter.otp_request_ip` par adresse IP (`ClientIp::limiterKey`). Une nouvelle demande **annule** la précédente
   (`pending` → `expired`).
4. Générer `code` (`random_int`) et `requestId` (`bin2hex(random_bytes(32))`), enregistrer la demande.
5. Répondre :

```json
{
  "requestId": "9f2c…",
  "code": "DRC-482913",
  "waLink": "https://wa.me/237659881683?text=Mon%20code%20DR%20Choco%20%3A%20DRC-482913",
  "expiresIn": 600
}
```

Le texte du lien est construit avec `rawurlencode()` et ne contient **que** le code (pas de nom, pas de lien).

### 4.2 `POST /api/auth/whatsapp/status` — public

Corps : `{ "requestId": "9f2c…" }` — appelé par le front toutes les 2-3 s (limiter dédié, ex. 60/min par IP).

| État de la demande | Réponse |
|---|---|
| introuvable / expirée | `410 WHATSAPP_REQUEST_EXPIRED` → le front propose de recommencer |
| `pending` | `200 { "status": "pending", "expiresIn": 412 }` |
| `mismatch` | `422 WHATSAPP_NUMBER_MISMATCH` « Le message doit être envoyé depuis le WhatsApp du 6 59 88 16 83. » |
| `confirmed` | **ouvre la session** : même travail et même réponse que `/api/auth/otp/verify` (compte créé au besoin, `isNewUser`, `setLastLoginAt`, `RefreshTokenService::createAccessToken` + `createForUser`, cookie `drc_refresh`), puis `status = consumed` — le tout dans **une transaction**, ligne verrouillée (`forUpdate`) pour qu'un double appel n'ouvre pas deux sessions |
| `consumed` | `410` (déjà utilisée) |

👉 Extraire la partie « créer le compte + ouvrir la session » de `OtpService::verifyCode()` dans un service commun
(ex. `App\Auth\LoginOpener`) utilisé par les deux connexions : aucune duplication, comportement identique.

### 4.3 `GET /api/webhooks/whatsapp` — vérification par Meta (public)

```php
if ($request->query->get('hub_mode') === 'subscribe'
    && hash_equals($this->verifyToken, (string) $request->query->get('hub_verify_token'))) {
    return new Response((string) $request->query->get('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
}
return new Response('', 403);
```

(PHP transforme `hub.mode` en `hub_mode` : c'est normal.)

### 4.4 `POST /api/webhooks/whatsapp` — réception des messages (public)

1. **Signature** : `hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret), $request->headers->get('X-Hub-Signature-256', ''))`
   → sinon `401` et journal d'avertissement. Calculer sur le **corps brut**, jamais sur le JSON re-encodé.
2. Parcourir `entry[].changes[].value.messages[]`. Ignorer tout ce qui n'est pas `type = "text"`
   (les `statuses[]` — accusés de livraison — arrivent aussi ici : les ignorer).
3. Pour chaque message : `from` (`"237659881683"`, sans +), `text.body`, `id` (wamid).
   - `wamid` déjà traité → ignorer (idempotence).
   - Extraire `/DRC-\d{6}/i` du texte ; aucun code → ignorer (ou répondre « Envoyez le message prérempli depuis le site »).
   - Chercher la demande `pending` avec ce code, non expirée.
   - Normaliser l'expéditeur : `PhoneNumber::normalizeCameroonMobile($from)` (accepte déjà `237` + 9 chiffres).
   - Expéditeur **≠** `phone` de la demande → `status = mismatch`. Sinon → `status = confirmed`, `confirmedAt`, `waMessageId`.
4. Répondre **toujours `200` rapidement** (même si le code est faux) : sinon Meta renvoie le webhook en boucle
   puis désactive l'abonnement. Les journaux masquent le numéro (`PhoneNumber::mask`).
5. Optionnel — accusé au client (service `App\Service\Notification\WhatsappClient`, `HttpClientInterface`) :

```http
POST https://graph.facebook.com/{WHATSAPP_GRAPH_VERSION}/{WHATSAPP_PHONE_NUMBER_ID}/messages
Authorization: Bearer {WHATSAPP_TOKEN}

{ "messaging_product": "whatsapp", "to": "237659881683", "type": "text",
  "text": { "body": "✅ Numéro vérifié. Retournez sur DR Choco, la connexion se fait automatiquement." } }
```

Un échec de cet envoi ne doit **jamais** annuler la confirmation (journaliser seulement).

---

## 5. Sécurité Symfony (`config/packages/security.yaml`)

Les nouvelles routes sont publiques et sans JWT, comme `otp/request` et `otp/verify` :

```yaml
firewalls:
    api_auth:
        pattern: ^/api/(auth/(otp/request|otp/verify|whatsapp/start|whatsapp/status|refresh|logout)|admin/auth/(login|refresh|logout)|webhooks/whatsapp)$
        stateless: true
        security: false

access_control:
    - { path: ^/api/auth/whatsapp/(start|status)$, roles: PUBLIC_ACCESS, methods: [POST] }
    - { path: ^/api/webhooks/whatsapp$, roles: PUBLIC_ACCESS, methods: [GET, POST] }
```

À vérifier aussi : `AuthOriginGuard` (contrôle d'origine) et `ClientWriteRateLimitSubscriber` ne doivent pas
bloquer le webhook — Meta appelle depuis ses serveurs, sans en-tête `Origin` du site.

---

## 6. Ce qui reste du SMS

`OtpService` / `SmsOtpSender` restent en place comme **solution de secours** (client sans WhatsApp sur ce numéro) :
le front affiche « Pas de WhatsApp ? Recevoir un code par SMS » qui appelle l'ancien `/api/auth/otp/request`.
Le plafond `OTP_DAILY_SMS_LIMIT` continue de protéger le budget Twilio.

---

## 7. Tests

- Faux `WhatsappClient` en environnement `test` (comme `RecordingOtpSender`) : aucun appel réel à Meta.
- Scénarios : start → webhook signé avec le bon code et le bon numéro → status renvoie la session ;
  mauvais numéro → `mismatch` ; signature fausse → 401 ; même `wamid` deux fois → une seule confirmation ;
  demande expirée ; double appel concurrent de `status` → une seule session ; limites de `start`.
- Test manuel : `ngrok http 8000`, URL ngrok dans la console Meta, envoyer le message depuis son téléphone,
  suivre `var/log/dev.log`.

---

## 8. Ordre de mise en place

1. Variables `.env.local` + token permanent.
2. Entité `WhatsappLoginRequest` + migration.
3. Route webhook GET → enregistrer l'URL dans la console Meta (§2).
4. Route webhook POST (signature, confirmation, idempotence).
5. `LoginOpener` extrait d'`OtpService`, puis routes `start` et `status`.
6. `security.yaml`, purge, tests.
7. Publier l'app Meta, puis faire la vérification d'entreprise (lève les limites de Meta).

---

## 9. Ce qui a été codé (27/11/2026)

Tout ce plan est en place, avec ces différences :

| Plan | Code | Pourquoi |
|---|---|---|
| Identifiants dans `.env.local` | Numéro et identifiants **publics** (numéro, ID du numéro, ID du compte, version) déjà dans `.env` ; seuls les **3 secrets** vont dans `.env.local` | Moins de réglages à faire sur le serveur |
| Expéditeur différent → `mismatch`, demande perdue | La demande **reste ouverte** ; `status` répond `pending` avec `wrongSender: true` et l'app explique d'où envoyer le message | Un message envoyé par erreur depuis un autre WhatsApp ne bloque pas le vrai client |
| 3 demandes / 10 min, 10 / jour | **5** / 10 min et **20** / jour par numéro ; 30 en 10 min par adresse IP (limite `whatsapp_start_ip`) | Rien n'est payé par demande ; un client qui revient plusieurs fois de WhatsApp ne doit pas être bloqué |
| Demande valable 10 min | 10 min, **puis encore 10 min après le message reçu** | Laisser le temps de revenir de WhatsApp (app fermée par le téléphone) |
| Réponse optionnelle au client | « ✅ Numéro vérifié… » à la confirmation ; une aide ou un refus (code expiré, autre numéro, message sans code) **une fois par heure au plus** par expéditeur ; envoyées après la réponse à Meta | Meta veut une réponse rapide ; pas de messages en rafale |
| — | `start` répond aussi `businessNumber` ; **503 `WHATSAPP_UNAVAILABLE`** tant que `WHATSAPP_APP_SECRET` est vide : l'app envoie alors le code par SMS sans rien demander | Mise en ligne possible avant le réglage de Meta |
| — | `app:diagnostic` lit le numéro chez Meta avec le jeton (aucun message envoyé) | Vérifier le jeton sans téléphone |

Fichiers : `src/Auth/Whatsapp/` (réglages, connexion, messages reçus, réponses), `src/Auth/LoginOpener.php`,
`src/Controller/Auth/WhatsappLoginController.php`, `src/Controller/Webhook/WhatsappWebhookController.php`,
migration `Version20261127100000`, tests `tests/Api/Auth/WhatsappLoginTest.php`.

Côté app : « Continuer avec WhatsApp » par défaut, code affiché et bouton « Ouvrir WhatsApp » (message déjà écrit),
vérification toutes les 2,5 s et dès le retour sur l'app, reprise si le téléphone a fermé l'app (demande gardée 10 min
sur le téléphone), « Pas de WhatsApp ? Recevoir un code par SMS ». Contrat : `docs/CONTRAT_API.md` §4.5 du front.

**Reste à faire, côté Meta, à la mise en ligne** : remplir les 3 secrets dans `.env.local`, puis `php bin/console
cache:clear` ; enregistrer l'adresse de rappel `https://drchoco.com/api/webhooks/whatsapp` (champ `messages`) ;
publier l'app (mode Live) ; `php bin/console app:diagnostic --production`.
