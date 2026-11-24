<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P9 (sécurité) : date d'envoi d'un code SMS (un code dont le SMS n'est pas parti ne peut pas être saisi).
 */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P9 : sécurité (codes SMS)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE otp_codes ADD sent_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE otp_codes SET sent_at = created_at');
        // Vie privée : l'adresse IP et le navigateur ne sont plus enregistrés avec les sessions (lus nulle part).
        $this->addSql('UPDATE refresh_tokens SET created_from_ip = NULL, user_agent = NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE otp_codes DROP sent_at');
    }
}
