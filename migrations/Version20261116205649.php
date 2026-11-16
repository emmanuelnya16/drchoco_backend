<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5 : réservations (articles copiés, historique, preuves de paiement privées) ; prix et boutique de départ
 * des agences de livraison.
 */
final class Version20261116205649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P5 : réservations, preuves de paiement, prix des agences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reservation_events (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(24) NOT NULL, actor VARCHAR(10) NOT NULL, note VARCHAR(255) DEFAULT NULL, admin_id INT DEFAULT NULL, admin_name VARCHAR(120) DEFAULT NULL, at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX IDX_1B761ED1B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE reservation_items (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL, product_id VARCHAR(40) NOT NULL, product_slug VARCHAR(160) NOT NULL, product_name VARCHAR(160) NOT NULL, image VARCHAR(500) DEFAULT \'\' NOT NULL, variant_id VARCHAR(80) NOT NULL, size VARCHAR(20) NOT NULL, color_code VARCHAR(40) NOT NULL, color_name VARCHAR(60) DEFAULT \'\' NOT NULL, color_hex VARCHAR(7) DEFAULT \'\' NOT NULL, unit_price INT NOT NULL, quantity INT NOT NULL, city VARCHAR(20) NOT NULL, seller_id INT DEFAULT NULL, seller_name VARCHAR(120) DEFAULT NULL, reservation_id INT NOT NULL, INDEX idx_reservation_item_product (product_id), INDEX idx_reservation_item_seller (seller_id), INDEX IDX_A26139EAB83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE reservation_proofs (id INT AUTO_INCREMENT NOT NULL, storage_path VARCHAR(255) NOT NULL, mime_type VARCHAR(20) NOT NULL, bytes INT NOT NULL, width INT NOT NULL, height INT NOT NULL, sha256 VARCHAR(64) NOT NULL, operator VARCHAR(10) NOT NULL, payer_phone VARCHAR(20) DEFAULT NULL, transaction_id VARCHAR(64) DEFAULT NULL, status VARCHAR(12) NOT NULL, uploaded_at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX idx_reservation_proof_sha (sha256), INDEX IDX_AB66D1B9B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE reservations (id INT AUTO_INCREMENT NOT NULL, ref VARCHAR(16) DEFAULT NULL, status VARCHAR(24) NOT NULL, shop_city VARCHAR(20) NOT NULL, reception_mode VARCHAR(10) NOT NULL, customer_first_name VARCHAR(100) NOT NULL, customer_last_name VARCHAR(100) DEFAULT \'\' NOT NULL, customer_phone VARCHAR(20) NOT NULL, delivery_city_id VARCHAR(40) DEFAULT NULL, delivery_city_name VARCHAR(100) DEFAULT NULL, district VARCHAR(120) DEFAULT NULL, landmark VARCHAR(255) DEFAULT NULL, agency_id VARCHAR(40) DEFAULT NULL, agency JSON DEFAULT NULL, pickup_shop JSON DEFAULT NULL, recipient_name VARCHAR(120) DEFAULT NULL, recipient_phone VARCHAR(20) DEFAULT NULL, notes VARCHAR(255) DEFAULT NULL, subtotal INT NOT NULL, delivery_fee INT NOT NULL, total INT NOT NULL, delivery_fee_payment VARCHAR(12) NOT NULL, hold_hours SMALLINT NOT NULL, expires_at DATETIME NOT NULL, expiring_notified_at DATETIME DEFAULT NULL, payment_operator VARCHAR(10) DEFAULT NULL, rejection_reason VARCHAR(255) DEFAULT NULL, rejected_at DATETIME DEFAULT NULL, idempotency_key VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_4DA239146F3EA3 (ref), INDEX idx_reservation_due (status, expires_at), INDEX idx_reservation_user_updated (user_id, updated_at), INDEX idx_reservation_shop (shop_city, status, updated_at), UNIQUE INDEX uniq_reservation_idempotency (user_id, idempotency_key), INDEX IDX_4DA239A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE reservation_events ADD CONSTRAINT FK_1B761ED1B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_items ADD CONSTRAINT FK_A26139EAB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_proofs ADD CONSTRAINT FK_AB66D1B9B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA239A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE agencies ADD fee INT DEFAULT NULL, ADD departure_city VARCHAR(20) DEFAULT NULL');
        // Agences déjà enregistrées : leur prix devient celui de leur ville (modifiable ensuite, agence par agence).
        $this->addSql('UPDATE agencies a INNER JOIN delivery_cities c ON c.id = a.city_id SET a.fee = c.fee WHERE a.fee IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_events DROP FOREIGN KEY FK_1B761ED1B83297E7');
        $this->addSql('ALTER TABLE reservation_items DROP FOREIGN KEY FK_A26139EAB83297E7');
        $this->addSql('ALTER TABLE reservation_proofs DROP FOREIGN KEY FK_AB66D1B9B83297E7');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA239A76ED395');
        $this->addSql('DROP TABLE reservation_events');
        $this->addSql('DROP TABLE reservation_items');
        $this->addSql('DROP TABLE reservation_proofs');
        $this->addSql('DROP TABLE reservations');
        $this->addSql('ALTER TABLE agencies DROP fee, DROP departure_city');
    }
}
