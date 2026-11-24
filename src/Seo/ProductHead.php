<?php

namespace App\Seo;

use App\Entity\Product;
use Symfony\Component\HttpFoundation\Request;

/**
 * Balises de la fiche d'un article (contrat §2.12), mêmes valeurs que src/features/catalog/product/ProductSeo.tsx :
 * l'app les retrouve en démarrant et les met à jour au lieu de les doubler.
 */
final class ProductHead
{
    /** Même identifiant que le front (JSON_LD_ID de ProductSeo.tsx). */
    public const JSON_LD_ID = 'product-jsonld-schema';

    public function __construct(
        private readonly SiteUrl $site,
        private readonly ShareImage $images,
    ) {}

    public function build(Product $product, Request $request): HeadTags
    {
        $url = $this->site->to('/produit/' . $product->getSlug());
        $price = SeoText::price($product->getPrice());
        $shareTitle = $product->getName() . ' — ' . $price;
        $description = SeoText::description($product->getDescription());
        $image = $this->images->share($product, $request);
        $inStock = $product->getTotalStock() > 0;

        $head = (new HeadTags())
            ->title($shareTitle . ' | ' . SeoText::SITE_NAME)
            ->name('description', $description)
            ->canonical($url)
            ->property('og:site_name', SeoText::SITE_NAME)
            ->property('og:locale', 'fr_FR')
            ->property('og:type', 'product')
            ->property('og:title', $shareTitle)
            ->property('og:description', $description)
            ->property('og:url', $url)
            ->property('og:image', $image['url'])
            ->property('og:image:alt', $product->getName());
        if ($image['width'] !== null && $image['height'] !== null) {
            $head->property('og:image:width', (string) $image['width'])->property('og:image:height', (string) $image['height']);
        }

        return $head
            ->property('product:price:amount', (string) $product->getPrice())
            ->property('product:price:currency', 'XAF')
            ->property('product:availability', $inStock ? 'in stock' : 'out of stock')
            ->name('twitter:card', 'summary_large_image')
            ->jsonLd(self::JSON_LD_ID, [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $product->getName(),
                'image'       => [$this->images->photo($product, $request) ?? $this->images->defaultUrl()],
                'description' => $product->getDescription(),
                'sku'         => $product->getId(),
                'url'         => $url,
                'brand'       => ['@type' => 'Brand', 'name' => SeoText::SITE_NAME],
                'offers'      => [
                    '@type'         => 'Offer',
                    'url'           => $url,
                    'priceCurrency' => 'XAF',
                    'price'         => (string) $product->getPrice(),
                    'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                ],
            ]);
    }
}
