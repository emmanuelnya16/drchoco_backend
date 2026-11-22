<?php

namespace App\Admin\Content;

use App\Api\ApiDate;
use App\Api\ApiException;
use App\Entity\ContentPage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Pages de contenu dans l'administration (P8) : 9 pages fixes (légales, aide, questions fréquentes), modifiées en
 * Markdown et publiées tout de suite. Ni création ni suppression. Les passages « [À COMPLÉTER …] » restants sont
 * comptés pour guider la boutique.
 */
final class PageEditor
{
    /** Ordre et type des pages (registre du front : src/features/content/registry.ts). */
    public const PAGES = [
        'conditions-generales'  => ContentPage::KIND_LEGAL,
        'annulation-retours'    => ContentPage::KIND_LEGAL,
        'confidentialite'       => ContentPage::KIND_LEGAL,
        'mentions-legales'      => ContentPage::KIND_LEGAL,
        'comment-reserver'      => ContentPage::KIND_HELP,
        'paiement-mobile-money' => ContentPage::KIND_HELP,
        'livraison-retrait'     => ContentPage::KIND_HELP,
        'suivre-ma-reservation' => ContentPage::KIND_HELP,
        'questions-frequentes'  => ContentPage::KIND_FAQ,
    ];
    private const PLACEHOLDER = '/\[À COMPLÉTER[^\]]*\]/iu';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array{pages: list<array<string, mixed>>, totalMissingPlaceholders: int} */
    public function list(): array
    {
        $pages = $this->em->getRepository(ContentPage::class)->findAll();
        $order = array_flip(array_keys(self::PAGES));
        usort($pages, fn (ContentPage $a, ContentPage $b) => [$order[$a->getSlug()] ?? 99, $a->getTitle()] <=> [$order[$b->getSlug()] ?? 99, $b->getTitle()]);

        $items = array_map(fn (ContentPage $p) => $this->summary($p), $pages);

        return ['pages' => $items, 'totalMissingPlaceholders' => array_sum(array_column($items, 'missingPlaceholdersCount'))];
    }

    public function find(string $slug): ContentPage
    {
        $page = preg_match(ContentPage::SLUG_PATTERN, $slug) === 1 ? $this->em->find(ContentPage::class, $slug) : null;

        return $page ?? throw new ApiException(404, 'PAGE_NOT_FOUND', 'Cette page est introuvable.', ['slug' => mb_substr($slug, 0, 80)]);
    }

    /** @param array<string, mixed> $body `{title, body}` */
    public function update(ContentPage $page, array $body): ContentPage
    {
        $errors = [];
        $title = $body['title'] ?? null;
        $text = $body['body'] ?? null;
        if (!\is_string($title) || mb_strlen(trim($title)) < 2 || mb_strlen(trim($title)) > 160) {
            $errors['title'] = 'Le titre doit faire de 2 à 160 caractères.';
        }
        if (!\is_string($text) || mb_strlen($text) > ContentPage::BODY_MAX_LENGTH) {
            $errors['body'] = sprintf('Le texte doit faire %s caractères au plus.', number_format(ContentPage::BODY_MAX_LENGTH, 0, ',', "\u{202F}"));
        } elseif (preg_match('//u', $text) !== 1) {
            $errors['body'] = 'Le texte contient des caractères illisibles : recopiez-le puis réessayez.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors, (string) reset($errors));
        }

        $page->setTitle(FrenchTypography::spacing(trim(preg_replace('/\s+/u', ' ', $title) ?? $title)))
            ->setBody(FrenchTypography::spacing(trim(str_replace("\r\n", "\n", $text))))
            ->setUpdatedAt(new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp()));
        $this->em->flush();

        return $page;
    }

    /** @return array<string, mixed> `AdminContentPageSummary` */
    public function summary(ContentPage $p): array
    {
        $placeholders = self::placeholders($p->getBody());

        return [
            'slug'                     => $p->getSlug(),
            'title'                    => $p->getTitle(),
            'kind'                     => self::PAGES[$p->getSlug()] ?? $p->getKind(),
            'updatedAt'                => ApiDate::format($p->getUpdatedAt()),
            'missingPlaceholdersCount' => \count($placeholders),
            'placeholders'             => $placeholders,
        ];
    }

    /** @return array<string, mixed> `AdminContentPageDetail` */
    public function detail(ContentPage $p): array
    {
        $summary = $this->summary($p);

        return [
            'slug'                     => $summary['slug'],
            'title'                    => $summary['title'],
            'kind'                     => $summary['kind'],
            'body'                     => $p->getBody(),
            'updatedAt'                => $summary['updatedAt'],
            'missingPlaceholdersCount' => $summary['missingPlaceholdersCount'],
            'placeholders'             => $summary['placeholders'],
        ];
    }

    /** @return list<string> passages « [À COMPLÉTER …] » restants */
    public static function placeholders(string $body): array
    {
        preg_match_all(self::PLACEHOLDER, $body, $matches);

        return array_values($matches[0]);
    }
}
