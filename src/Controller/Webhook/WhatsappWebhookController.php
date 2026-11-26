<?php

namespace App\Controller\Webhook;

use App\Api\JsonBody;
use App\Auth\Whatsapp\WhatsappWebhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adresse de rappel de l'app Meta « Dr Choco OTP » (WHATSAPP_OTP.md §2 et §4) : https://<domaine>/api/webhooks/whatsapp.
 * Appelée par les serveurs de Meta, sans jeton : la vérification se fait par le jeton de vérification (GET) et par la
 * signature de chaque envoi (POST).
 */
final class WhatsappWebhookController
{
    /** Envoi de Meta : un lot de messages tient en quelques Ko. */
    private const MAX_BYTES = 262_144;

    public function __construct(
        private readonly WhatsappWebhook $webhook,
    ) {}

    /** GET — Meta vérifie l'adresse (« Vérifier et enregistrer ») : hub.mode, hub.verify_token, hub.challenge. */
    #[Route('/webhooks/whatsapp', name: 'api_webhook_whatsapp_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        // PHP transforme « hub.mode » en « hub_mode »
        $challenge = $this->webhook->verifySubscription(
            self::stringOrNull($request->query->all()['hub_mode'] ?? null),
            self::stringOrNull($request->query->all()['hub_verify_token'] ?? null),
            self::stringOrNull($request->query->all()['hub_challenge'] ?? null),
        );

        return new Response($challenge ?? '', $challenge === null ? 403 : 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** POST — messages reçus. Réponse 200 rapide dans tous les cas (sauf signature fausse) : Meta renverrait l'envoi. */
    #[Route('/webhooks/whatsapp', name: 'api_webhook_whatsapp_receive', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        $this->webhook->handle(JsonBody::content($request, self::MAX_BYTES), $request->headers->get('X-Hub-Signature-256'));

        return new JsonResponse(['received' => true], 200, ['Cache-Control' => 'no-store']);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && \strlen($value) <= 512 ? $value : null;
    }
}
