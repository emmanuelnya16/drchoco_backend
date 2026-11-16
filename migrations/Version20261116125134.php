<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2 : comptes clients — codes de connexion par SMS, carnet d'adresses, favoris, date de dernière connexion.
 */
final class Version20261116125134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2 : comptes clients (codes SMS, adresses, favoris)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE addresses (id VARCHAR(40) NOT NULL, label VARCHAR(20) NOT NULL, city VARCHAR(100) NOT NULL, district VARCHAR(120) NOT NULL, landmark VARCHAR(255) NOT NULL, reception_mode VARCHAR(20) DEFAULT \'delivery\' NOT NULL, agency_id VARCHAR(40) DEFAULT NULL, recipient_name VARCHAR(120) DEFAULT NULL, recipient_phone VARCHAR(20) DEFAULT NULL, is_default TINYINT DEFAULT 0 NOT NULL, sequence INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX idx_address_user_order (user_id, is_default, sequence), INDEX IDX_6FCA7516A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE favorites (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, product_id VARCHAR(40) NOT NULL, UNIQUE INDEX uniq_favorite_user_product (user_id, product_id), INDEX IDX_E46960F5A76ED395 (user_id), INDEX IDX_E46960F54584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE otp_codes (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(20) NOT NULL, code_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, attempts_left SMALLINT DEFAULT 5 NOT NULL, locked_until DATETIME DEFAULT NULL, consumed_at DATETIME DEFAULT NULL, request_ip VARCHAR(45) DEFAULT NULL, INDEX idx_otp_phone_created (phone, created_at), INDEX idx_otp_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE addresses ADD CONSTRAINT FK_6FCA7516A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F54584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE users ADD last_login_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE addresses DROP FOREIGN KEY FK_6FCA7516A76ED395');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5A76ED395');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F54584665A');
        $this->addSql('DROP TABLE addresses');
        $this->addSql('DROP TABLE favorites');
        $this->addSql('DROP TABLE otp_codes');
        $this->addSql('ALTER TABLE users DROP last_login_at');
    }
}
