<?php

namespace App\Tests;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Base des tests des réservations (P5) : client connecté, corps de demande comme les envoie CheckoutPage,
 * envoi de captures de paiement (multipart). Données de départ : hoodie-barbele-noir (Yaoundé, 45 000 FCFA,
 * prod-01-noir-s : 4 pièces), tshirt-lincompris-oversize (Douala, 18 000 FCFA, prod-02-noir-s : 8 pièces).
 */
abstract class ReservationTestCase extends AccountTestCase
{
    protected const YDE_VARIANT = 'prod-01-noir-s';
    protected const DLA_VARIANT = 'prod-02-noir-s';

    private int $keys = 0;

    /** Captures écrites pendant le test : la base est annulée à la fin, les fichiers sont effacés ici. */
    protected function tearDown(): void
    {
        $dir = static::getContainer()->getParameter('app.private_media_dir');
        parent::tearDown();
        if (\is_string($dir) && str_contains($dir, 'test-private') && is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
    }

    /** @return array<string, mixed> */
    protected function pickup(string $variantId = self::YDE_VARIANT, int $quantity = 1, array $override = []): array
    {
        return $override + [
            'receptionMode' => 'pickup',
            'items'         => [['productId' => substr($variantId, 0, 7), 'variantId' => $variantId, 'quantity' => $quantity]],
            'firstName'     => 'Junior',
            'lastName'      => 'Mbarga',
            'phone'         => self::PHONE,
            'city'          => 'Yaoundé', // le front envoie toujours « Yaoundé » pour un retrait
            'recipientType' => 'self',
            'recipientName' => 'Junior Mbarga',
            'recipientPhone' => self::PHONE,
        ];
    }

    /** @return array<string, mixed> */
    protected function reserve(string $token, array $body, ?string $key = null): array
    {
        $key ??= 'test-key-' . (++$this->keys) . '-' . bin2hex(random_bytes(4));
        $this->client->request('POST', '/api/reservations', [], [], [
            'HTTP_ACCEPT'          => 'application/json',
            'HTTP_AUTHORIZATION'   => 'Bearer ' . $token,
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    protected function stock(string $variantId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT stock FROM product_variants WHERE id = ?', [$variantId]);
    }

    /** Change le statut directement en base (actions de la boutique, P6). */
    protected function forceStatus(string $ref, string $status, array $extra = []): void
    {
        $this->em()->getConnection()->update('reservations', ['status' => $status] + $extra, ['ref' => $ref]);
        $this->em()->clear();
    }

    /** @return array<string, mixed> */
    protected function uploadProof(string $token, string $ref, ?UploadedFile $file, array $fields = ['operator' => 'orange']): array
    {
        $this->client->request('POST', '/api/reservations/' . $ref . '/proof', $fields, $file !== null ? ['file' => $file] : [], [
            'HTTP_ACCEPT'        => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Fichier temporaire au contenu donné (PNG par défaut, avec une métadonnée texte « GPS »). */
    protected function file(?string $bytes = null, string $name = 'capture.png', string $mime = 'image/png'): UploadedFile
    {
        $bytes ??= self::pngWithText('Comment', 'GPS 3.8480,11.5021');
        $path = tempnam(sys_get_temp_dir(), 'drc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /** PNG d'un pixel dont la couleur dépend de `$text`, avec ce texte en métadonnée (bloc tEXt). */
    protected static function pngWithText(string $keyword, string $text): string
    {
        $png = self::plainPng($text);
        $data = $keyword . "\0" . $text;
        $chunk = pack('N', \strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        $ihdrEnd = 8 + 25; // signature + bloc IHDR (13 octets de données)

        return substr($png, 0, $ihdrEnd) . $chunk . substr($png, $ihdrEnd);
    }

    /** La même image sans métadonnée (ce que le serveur garde). */
    protected static function plainPng(string $text): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', \strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $pixel = substr(hash('sha256', $text, true), 0, 3);

        return "\x89PNG\r\n\x1A\n" . $chunk('IHDR', pack('NN', 1, 1) . "\x08\x02\0\0\0") . $chunk('IDAT', (string) gzcompress("\0" . $pixel)) . $chunk('IEND', '');
    }
}
