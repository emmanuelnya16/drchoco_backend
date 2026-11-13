<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113170102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Socle P0 : clients (users), équipe (admins) et sessions (refresh_tokens).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admins (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(20) DEFAULT NULL, password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, city VARCHAR(60) DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A2E0150FE7927C74 (email), UNIQUE INDEX UNIQ_A2E0150F444F97DD (phone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE refresh_tokens (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(128) NOT NULL, user_type VARCHAR(10) NOT NULL, user_id INT NOT NULL, family_id VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, session_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, created_from_ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, is_used TINYINT DEFAULT 0 NOT NULL, used_at DATETIME DEFAULT NULL, is_revoked TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1B3BC57DA (token_hash), INDEX idx_refresh_user (user_type, user_id), INDEX idx_refresh_family (family_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(20) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9444F97DD (phone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE admins');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE users');
    }
}
