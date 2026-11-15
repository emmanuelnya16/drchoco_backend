<?php

namespace App\Entity;

use App\Repository\ContentPageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Page de contenu en Markdown : pages légales, pages d'aide et questions fréquentes.
 * Modifiable dans l'administration (P8).
 */
#[ORM\Entity(repositoryClass: ContentPageRepository::class)]
#[ORM\Table(name: 'content_pages')]
class ContentPage
{
    public const KIND_LEGAL = 'legal';
    public const KIND_HELP  = 'help';
    public const KIND_FAQ   = 'faq';

    // Minuscules, chiffres et tirets, 80 caractères au plus (contrat §12.1).
    public const SLUG_PATTERN = '/^[a-z0-9-]{1,80}$/';

    // Corps limité à 100 000 caractères (contrat §12.1).
    public const BODY_MAX_LENGTH = 100000;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 160)]
    private string $title;

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'legal'])]
    private string $kind = self::KIND_LEGAL;

    // MEDIUMTEXT : assez pour 100 000 caractères multioctets.
    #[ORM\Column(type: 'text', length: 16777215)]
    private string $body = '';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isPublished = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $title)
    {
        $this->slug = $slug;
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSlug(): string { return $this->slug; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $v): static { $this->kind = $v; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $v): static { $this->body = $v; return $this; }

    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $v): static { $this->isPublished = $v; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): static { $this->updatedAt = $v; return $this; }
}
