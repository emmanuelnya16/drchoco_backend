<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P9 (suite) : remboursement clos par la boutique ; date du premier échec d'un appareil injoignable.
 */
final class Version20261124150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P9 : remboursement clos, appareils injoignables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations ADD refund_settled_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE push_subscriptions ADD failing_since DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations DROP refund_settled_at');
        $this->addSql('ALTER TABLE push_subscriptions DROP failing_since');
    }
}
