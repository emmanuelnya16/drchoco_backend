<?php

namespace App\Push;

/**
 * Notification push telle que le service worker du front l'attend (src/pwa/pushPayload.ts) :
 * `{type, title, body, url, ref?, image?, tag?}`, plus les options d'envoi (durée de vie, urgence, sujet).
 */
final class PushMessage
{
    public const URGENCY_NORMAL = 'normal';
    public const URGENCY_HIGH = 'high';
    public const DEFAULT_TTL = 86400;

    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly ?string $ref = null,
        public readonly ?string $image = null,
        public readonly ?string $tag = null,
        public readonly int $ttl = self::DEFAULT_TTL,
        public readonly string $urgency = self::URGENCY_NORMAL,
        public readonly ?string $topic = null,
    ) {}

    /** Contenu JSON (4 Ko au plus pour le service de push : les textes restent courts). */
    public function payload(): string
    {
        return json_encode(array_filter([
            'type'  => $this->type,
            'title' => $this->title,
            'body'  => $this->body,
            'url'   => $this->url,
            'ref'   => $this->ref,
            'image' => $this->image,
            'tag'   => $this->tag,
        ], fn ($v) => $v !== null), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
