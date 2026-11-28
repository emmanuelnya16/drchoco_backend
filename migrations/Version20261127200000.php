<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portefeuille de pièces (App\Wallet\CoinWallet) : solde du client, historique des mouvements, pièces utilisées et
 * réduction de chaque réservation. Les comptes existants commencent à 0 pièce (bonus réservé aux nouveaux comptes).
 */
final class Version20261127200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portefeuille de pièces : solde, mouvements, réduction des réservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD coins INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE reservations ADD coins_used INT DEFAULT 0 NOT NULL, ADD coins_discount INT DEFAULT 0 NOT NULL, ADD coins_refunded_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE coin_movements (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, reservation_id INT DEFAULT NULL, amount INT NOT NULL, balance_after INT NOT NULL, reason VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_coin_movements_user (user_id, created_at), INDEX IDX_COIN_MOVEMENTS_RESERVATION (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE coin_movements ADD CONSTRAINT FK_COIN_MOVEMENTS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coin_movements ADD CONSTRAINT FK_COIN_MOVEMENTS_RESERVATION FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE coin_movements');
        $this->addSql('ALTER TABLE reservations DROP coins_used, DROP coins_discount, DROP coins_refunded_at');
        $this->addSql('ALTER TABLE users DROP coins');
    }
}
