<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « J'ai bien reçu mon colis » : date à laquelle le client confirme la réception. Le statut ne change pas, la boutique
 * confirme toujours elle-même le retrait ou la livraison.
 */
final class Version20261128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réception du colis confirmée par le client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations ADD customer_received_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations DROP customer_received_at');
    }
}
