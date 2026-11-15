<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1 : catalogue public et référentiels (catégories, articles et variantes, looks, villes et agences,
 * pages de contenu, réglages de la boutique).
 */
final class Version20261115131808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P1 : catalogue public et référentiels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agencies (id VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, address VARCHAR(255) DEFAULT \'\' NOT NULL, phone VARCHAR(40) DEFAULT \'\' NOT NULL, opening_hours VARCHAR(120) DEFAULT \'\' NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, updated_at DATETIME DEFAULT NULL, city_id VARCHAR(40) NOT NULL, INDEX IDX_F65A4DC48BAC62AF (city_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE app_settings (name VARCHAR(60) NOT NULL, value JSON NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE categories (id VARCHAR(40) NOT NULL, slug VARCHAR(80) NOT NULL, name VARCHAR(80) NOT NULL, description VARCHAR(500) DEFAULT \'\' NOT NULL, image VARCHAR(500) DEFAULT \'\' NOT NULL, cover_image VARCHAR(500) DEFAULT \'\' NOT NULL, fallback_image VARCHAR(500) DEFAULT \'\' NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_3AF34668989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE content_pages (slug VARCHAR(80) NOT NULL, title VARCHAR(160) NOT NULL, kind VARCHAR(10) DEFAULT \'legal\' NOT NULL, body MEDIUMTEXT NOT NULL, is_published TINYINT DEFAULT 1 NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (slug)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE delivery_cities (id VARCHAR(40) NOT NULL, name VARCHAR(80) NOT NULL, fee INT DEFAULT 0 NOT NULL, is_local TINYINT DEFAULT 0 NOT NULL, has_agencies TINYINT DEFAULT 1 NOT NULL, estimated_delay VARCHAR(80) DEFAULT \'\' NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_C74C48E55E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE looks (id VARCHAR(40) NOT NULL, title VARCHAR(120) NOT NULL, subtitle VARCHAR(200) DEFAULT \'\' NOT NULL, image VARCHAR(500) DEFAULT \'\' NOT NULL, fallback VARCHAR(500) DEFAULT \'\' NOT NULL, product_slugs JSON NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE product_variants (id VARCHAR(80) NOT NULL, size VARCHAR(20) NOT NULL, color_code VARCHAR(40) NOT NULL, stock INT DEFAULT 0 NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, product_id VARCHAR(40) NOT NULL, INDEX idx_variant_size_stock (size, stock), UNIQUE INDEX uniq_variant_color_size (product_id, color_code, size), INDEX IDX_782839764584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE products (id VARCHAR(40) NOT NULL, slug VARCHAR(160) NOT NULL, name VARCHAR(160) NOT NULL, description LONGTEXT NOT NULL, composition VARCHAR(255) DEFAULT \'\' NOT NULL, care_instructions JSON NOT NULL, size_guide JSON NOT NULL, price INT NOT NULL, media_gallery JSON NOT NULL, colors JSON NOT NULL, sizes JSON NOT NULL, is_new TINYINT DEFAULT 0 NOT NULL, reservations_count INT DEFAULT 0 NOT NULL, city VARCHAR(60) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, category_id VARCHAR(40) NOT NULL, sub_category_id VARCHAR(40) DEFAULT NULL, seller_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_B3BA5A5A989D9B62 (slug), INDEX idx_product_active_city (is_active, city), INDEX idx_product_price (price), INDEX idx_product_created (created_at), INDEX idx_product_popular (reservations_count), INDEX IDX_B3BA5A5A12469DE2 (category_id), INDEX IDX_B3BA5A5AF7BFE87C (sub_category_id), INDEX IDX_B3BA5A5A8DE820D9 (seller_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE sub_categories (id VARCHAR(40) NOT NULL, slug VARCHAR(80) NOT NULL, name VARCHAR(80) NOT NULL, cover_image VARCHAR(500) DEFAULT \'\' NOT NULL, fallback_image VARCHAR(500) DEFAULT \'\' NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, category_id VARCHAR(40) NOT NULL, UNIQUE INDEX UNIQ_1638D5A5989D9B62 (slug), INDEX IDX_1638D5A512469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE agencies ADD CONSTRAINT FK_F65A4DC48BAC62AF FOREIGN KEY (city_id) REFERENCES delivery_cities (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_variants ADD CONSTRAINT FK_782839764584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id)');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5AF7BFE87C FOREIGN KEY (sub_category_id) REFERENCES sub_categories (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A8DE820D9 FOREIGN KEY (seller_id) REFERENCES admins (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sub_categories ADD CONSTRAINT FK_1638D5A512469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agencies DROP FOREIGN KEY FK_F65A4DC48BAC62AF');
        $this->addSql('ALTER TABLE product_variants DROP FOREIGN KEY FK_782839764584665A');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A12469DE2');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5AF7BFE87C');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A8DE820D9');
        $this->addSql('ALTER TABLE sub_categories DROP FOREIGN KEY FK_1638D5A512469DE2');
        $this->addSql('DROP TABLE agencies');
        $this->addSql('DROP TABLE app_settings');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE content_pages');
        $this->addSql('DROP TABLE delivery_cities');
        $this->addSql('DROP TABLE looks');
        $this->addSql('DROP TABLE product_variants');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE sub_categories');
    }
}
