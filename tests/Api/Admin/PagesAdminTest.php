<?php

namespace App\Tests\Api\Admin;

use App\Tests\AdminTestCase;

/**
 * Pages de contenu dans l'administration (P8) : liste, détail, modification publiée tout de suite, typographie,
 * repères « [À COMPLÉTER] ».
 */
final class PagesAdminTest extends AdminTestCase
{
    private function owner(): string
    {
        $this->account();

        return $this->adminToken('owner@drchoco.cm');
    }

    public function testListAndDetail(): void
    {
        $token = $this->owner();
        $data = $this->api('GET', '/api/admin/pages', $token);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['pages', 'totalMissingPlaceholders'], array_keys($data));
        self::assertSame(
            ['conditions-generales', 'annulation-retours', 'confidentialite', 'mentions-legales', 'comment-reserver', 'paiement-mobile-money', 'livraison-retrait', 'suivre-ma-reservation', 'questions-frequentes'],
            array_column($data['pages'], 'slug'),
        );
        self::assertSame(['legal', 'legal', 'legal', 'legal', 'help', 'help', 'help', 'help', 'faq'], array_column($data['pages'], 'kind'));
        self::assertSame(['slug', 'title', 'kind', 'updatedAt', 'missingPlaceholdersCount', 'placeholders'], array_keys($data['pages'][0]));
        self::assertSame(array_sum(array_column($data['pages'], 'missingPlaceholdersCount')), $data['totalMissingPlaceholders']);
        $legal = array_column($data['pages'], null, 'slug')['mentions-legales'];
        self::assertSame(\count($legal['placeholders']), $legal['missingPlaceholdersCount']);
        self::assertGreaterThan(0, $legal['missingPlaceholdersCount']);
        self::assertStringStartsWith('[À COMPLÉTER', $legal['placeholders'][0]);

        $detail = $this->api('GET', '/api/admin/pages/mentions-legales', $token);
        self::assertSame(['slug', 'title', 'kind', 'body', 'updatedAt', 'missingPlaceholdersCount', 'placeholders'], array_keys($detail));
        self::assertSame($this->getJson('/api/pages/mentions-legales')['body'], $detail['body']);

        $data = $this->api('GET', '/api/admin/pages/inconnue', $token);
        self::assertSame([404, 'PAGE_NOT_FOUND'], [$this->httpStatus(), $data['code']]);
        $this->api('GET', '/api/admin/pages/..%2Fetc', $token);
        self::assertSame(404, $this->httpStatus(), 'chemin piégé : introuvable');
    }

    public function testSavingPublishesWithFrenchTypography(): void
    {
        $token = $this->owner();
        $this->clock->sleep(3600);
        $body = "  # Mentions légales\r\n\r\nÉditeur : DR Choco L’Incompris ! Capital : 1 000 000 FCFA.\r\nOuvert de 9 h à 19 h, « tous les jours ».\r\n\r\nHébergeur : [À COMPLÉTER : nom de l’hébergeur]\r\n\r\nSite : https://drchoco.cm  ";
        $data = $this->api('PATCH', '/api/admin/pages/mentions-legales', $token, ['title' => '  Mentions   légales ', 'body' => $body]);
        self::assertSame(200, $this->httpStatus(), json_encode($data));

        $nbsp = "\u{00A0}";
        self::assertSame('Mentions légales', $data['title']);
        self::assertSame("# Mentions légales\n\nÉditeur{$nbsp}: DR Choco L’Incompris{$nbsp}! Capital{$nbsp}: 1{$nbsp}000{$nbsp}000{$nbsp}FCFA.\nOuvert de 9{$nbsp}h à 19{$nbsp}h, «{$nbsp}tous les jours{$nbsp}».\n\nHébergeur{$nbsp}: [À COMPLÉTER{$nbsp}: nom de l’hébergeur]\n\nSite{$nbsp}: https://drchoco.cm", $data['body'], 'adresse internet intacte');
        self::assertSame([1, ["[À COMPLÉTER{$nbsp}: nom de l’hébergeur]"]], [$data['missingPlaceholdersCount'], $data['placeholders']], 'repère toujours reconnu');
        self::assertSame($this->clock->now()->getTimestamp(), strtotime($data['updatedAt']));

        $public = $this->getJson('/api/pages/mentions-legales');
        self::assertSame([$data['title'], $data['body']], [$public['title'], $public['body']], 'publiée tout de suite');

        // Texte déjà corrigé : inchangé.
        $again = $this->api('PATCH', '/api/admin/pages/mentions-legales', $token, ['title' => $data['title'], 'body' => $data['body']]);
        self::assertSame($data['body'], $again['body']);

        foreach ([['title' => 'X', 'body' => 'Texte'], ['title' => 'Titre', 'body' => str_repeat('a', 100001)], ['title' => 'Titre'], ['title' => 12, 'body' => 'x']] as $invalid) {
            $result = $this->api('PATCH', '/api/admin/pages/mentions-legales', $token, $invalid);
            self::assertSame([422, 'VALIDATION_ERROR'], [$this->httpStatus(), $result['code']]);
        }
    }
}
