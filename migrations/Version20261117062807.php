<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P7 : notifications push (appareils abonnés, file d'attente d'envoi) ; index sur la date de création des
 * réservations (chiffres de ventes).
 */
final class Version20261117062807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P7 : notifications push, index des réservations par date de création';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_outbox (id BIGINT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, payload LONGTEXT NOT NULL, ttl INT NOT NULL, urgency VARCHAR(8) NOT NULL, topic VARCHAR(32) DEFAULT NULL, status VARCHAR(10) NOT NULL, attempts SMALLINT DEFAULT 0 NOT NULL, next_attempt_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, claim VARCHAR(32) DEFAULT NULL, locked_until DATETIME DEFAULT NULL, last_status SMALLINT DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, subscription_id INT NOT NULL, reservation_id INT DEFAULT NULL, INDEX idx_push_outbox_due (status, next_attempt_at), INDEX idx_push_outbox_claim (claim), INDEX IDX_415D04509A1887DC (subscription_id), INDEX IDX_415D0450B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE push_subscriptions (id INT AUTO_INCREMENT NOT NULL, owner_kind VARCHAR(5) NOT NULL, endpoint VARCHAR(1000) NOT NULL, endpoint_hash VARCHAR(64) NOT NULL, p256dh VARCHAR(120) NOT NULL, auth VARCHAR(40) NOT NULL, content_encoding VARCHAR(10) DEFAULT \'aes128gcm\' NOT NULL, platform VARCHAR(10) NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_success_at DATETIME DEFAULT NULL, failure_count SMALLINT DEFAULT 0 NOT NULL, last_status SMALLINT DEFAULT NULL, user_id INT DEFAULT NULL, admin_id INT DEFAULT NULL, INDEX idx_push_last_seen (last_seen_at), UNIQUE INDEX uniq_push_owner_endpoint (owner_kind, endpoint_hash), INDEX IDX_3FEC449DA76ED395 (user_id), INDEX IDX_3FEC449D642B8210 (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE push_outbox ADD CONSTRAINT FK_415D04509A1887DC FOREIGN KEY (subscription_id) REFERENCES push_subscriptions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_outbox ADD CONSTRAINT FK_415D0450B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscriptions ADD CONSTRAINT FK_3FEC449DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscriptions ADD CONSTRAINT FK_3FEC449D642B8210 FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_reservation_created ON reservations (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_outbox DROP FOREIGN KEY FK_415D04509A1887DC');
        $this->addSql('ALTER TABLE push_outbox DROP FOREIGN KEY FK_415D0450B83297E7');
        $this->addSql('ALTER TABLE push_subscriptions DROP FOREIGN KEY FK_3FEC449DA76ED395');
        $this->addSql('ALTER TABLE push_subscriptions DROP FOREIGN KEY FK_3FEC449D642B8210');
        $this->addSql('DROP TABLE push_outbox');
        $this->addSql('DROP TABLE push_subscriptions');
        $this->addSql('DROP INDEX idx_reservation_created ON reservations');
    }
}
