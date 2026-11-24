<?php

namespace App\Seo;

/**
 * Balises d'une page, insérées dans le <head> de index.html à la place des balises générales.
 *
 * Toutes les valeurs sont échappées ici (noms et descriptions sont saisis dans l'administration) : aucune ne peut
 * ouvrir une balise ni un script.
 */
final class HeadTags
{
    private string $title = '';
    private ?string $canonical = null;
    /** @var list<array{0: string, 1: string, 2: string}> [attribut, nom, valeur] */
    private array $metas = [];
    /** @var array<string, array<string, mixed>> données structurées par identifiant de balise */
    private array $jsonLd = [];

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function canonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    /** <meta name="…"> */
    public function name(string $name, string $content): self
    {
        $this->metas[] = ['name', $name, $content];

        return $this;
    }

    /** <meta property="…"> (Open Graph) */
    public function property(string $property, string $content): self
    {
        $this->metas[] = ['property', $property, $content];

        return $this;
    }

    /** @param array<string, mixed> $data données structurées Schema.org */
    public function jsonLd(string $id, array $data): self
    {
        $this->jsonLd[$id] = $data;

        return $this;
    }

    /** Page avec ces balises ; les balises générales correspondantes sont retirées. Sans <head> : page inchangée. */
    public function applyTo(string $html): string
    {
        $end = stripos($html, '</head>');
        if ($end === false) {
            return $html;
        }
        $head = substr($html, 0, $end);
        $rest = substr($html, $end);

        $head = preg_replace('~[ \t]*<title\b[^>]*+>.*?</title>[ \t]*\R?~is', '', $head) ?? $head;
        $head = preg_replace_callback(
            '~[ \t]*<(meta|link)\b((?:[^>"\']|"[^"]*+"|\'[^\']*+\')*+)>[ \t]*\R?~i',
            static fn (array $m): string => self::isReplaced(strtolower($m[1]), $m[2]) ? '' : $m[0],
            $head,
        ) ?? $head;

        // Juste après l'encodage (il doit rester au début de la page), avant les longues listes d'icônes : certains
        // robots ne lisent que le début du document.
        if (preg_match('~<meta\b[^>]*\bcharset\b[^>]*>~i', $head, $m, \PREG_OFFSET_CAPTURE) !== 1
            && preg_match('~<head\b[^>]*>~i', $head, $m, \PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $position = $m[0][1] + \strlen($m[0][0]);

        return substr($head, 0, $position) . "\n" . $this->render() . substr($head, $position) . $rest;
    }

    private function render(): string
    {
        $lines = ['<title>' . self::escape($this->title) . '</title>'];
        foreach ($this->metas as [$attribute, $name, $content]) {
            $lines[] = sprintf('<meta %s="%s" content="%s" />', $attribute, self::escape($name), self::escape($content));
        }
        if ($this->canonical !== null) {
            $lines[] = sprintf('<link rel="canonical" href="%s" />', self::escape($this->canonical));
        }
        foreach ($this->jsonLd as $id => $data) {
            // « < », « > » et « & » écrits en \u00xx : le texte ne peut pas fermer la balise <script>.
            $json = json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
            $lines[] = sprintf('<script type="application/ld+json" id="%s">%s</script>', self::escape($id), $json);
        }

        return implode('', array_map(static fn (string $line): string => '    ' . $line . "\n", $lines));
    }

    /** Balise générale remplacée par celles de la page : description, robots, adresse canonique, og:*, product:*, twitter:*. */
    private static function isReplaced(string $tag, string $attributes): bool
    {
        if ($tag === 'link') {
            return preg_match('~\brel\s*=\s*["\']?canonical\b~i', $attributes) === 1;
        }
        if (preg_match('~\b(?:name|property)\s*=\s*["\']?([a-z0-9:_-]+)~i', $attributes, $m) !== 1) {
            return false;
        }
        $name = strtolower($m[1]);

        return \in_array($name, ['description', 'robots'], true) || preg_match('/^(?:og|product|twitter):/', $name) === 1;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
