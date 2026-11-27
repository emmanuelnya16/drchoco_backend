<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Connexion par WhatsApp, nouvelle version : le client envoie une référence, la boutique lui répond avec un code à
 * 6 chiffres (saisi comme le code SMS). Les demandes en cours (10 min de validité) sont abandonnées.
 * Les codes de connexion gardent leur canal : seuls les SMS comptent dans le plafond quotidien de SMS.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexion par WhatsApp : référence et code renvoyé au client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS whatsapp_login_requests');
        $this->addSql('CREATE TABLE whatsapp_login_requests (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(20) NOT NULL, reference VARCHAR(8) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, code_sent_at DATETIME DEFAULT NULL, wa_message_id VARCHAR(128) DEFAULT NULL, wrong_sender_at DATETIME DEFAULT NULL, request_ip VARCHAR(45) DEFAULT NULL, INDEX idx_wa_login_phone (phone, created_at), INDEX idx_wa_login_created (created_at), UNIQUE INDEX uniq_wa_login_reference (reference), UNIQUE INDEX uniq_wa_login_message (wa_message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql("ALTER TABLE otp_codes ADD channel VARCHAR(10) DEFAULT 'sms' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE otp_codes DROP channel');
        $this->addSql('DROP TABLE whatsapp_login_requests');
        $this->addSql('CREATE TABLE whatsapp_login_requests (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(20) NOT NULL, code VARCHAR(12) NOT NULL, request_token_hash VARCHAR(64) NOT NULL, status VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, consumed_at DATETIME DEFAULT NULL, wa_message_id VARCHAR(128) DEFAULT NULL, wrong_sender_at DATETIME DEFAULT NULL, request_ip VARCHAR(45) DEFAULT NULL, INDEX idx_wa_login_code (code, status), INDEX idx_wa_login_phone (phone, created_at), INDEX idx_wa_login_created (created_at), UNIQUE INDEX uniq_wa_login_token (request_token_hash), UNIQUE INDEX uniq_wa_login_message (wa_message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }
}
