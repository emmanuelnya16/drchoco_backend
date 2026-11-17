<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P6 : gestion des réservations par l'équipe — photos du colis (privées), notes internes, dates de validation
 * du paiement et de colis prêt.
 */
final class Version20261117024302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P6 : photos du colis, notes internes, paiement validé et colis prêt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reservation_notes (id INT AUTO_INCREMENT NOT NULL, admin_id INT DEFAULT NULL, author VARCHAR(120) NOT NULL, text LONGTEXT NOT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX IDX_4264762BB83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE reservation_package_photos (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL, storage_path VARCHAR(255) NOT NULL, mime_type VARCHAR(20) NOT NULL, bytes INT NOT NULL, width INT NOT NULL, height INT NOT NULL, admin_id INT DEFAULT NULL, taken_at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX IDX_23B9D23CB83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE reservation_notes ADD CONSTRAINT FK_4264762BB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_package_photos ADD CONSTRAINT FK_23B9D23CB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservations ADD paid_at DATETIME DEFAULT NULL, ADD package_ready_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_reservation_paid ON reservations (paid_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_notes DROP FOREIGN KEY FK_4264762BB83297E7');
        $this->addSql('ALTER TABLE reservation_package_photos DROP FOREIGN KEY FK_23B9D23CB83297E7');
        $this->addSql('DROP TABLE reservation_notes');
        $this->addSql('DROP TABLE reservation_package_photos');
        $this->addSql('DROP INDEX idx_reservation_paid ON reservations');
        $this->addSql('ALTER TABLE reservations DROP paid_at, DROP package_ready_at');
    }
}
