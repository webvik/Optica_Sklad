<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260425103037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cable_spool (id INT AUTO_INCREMENT NOT NULL, reel_number VARCHAR(128) NOT NULL, vendor_product_code VARCHAR(64) DEFAULT NULL, total_length_m INT NOT NULL, initial_visible_m INT NOT NULL, current_remaining_m INT DEFAULT NULL, last_visible_m INT DEFAULT NULL, meter_sign INT DEFAULT NULL, fiber_count INT DEFAULT NULL, diameter_mm NUMERIC(4, 1) DEFAULT NULL, status VARCHAR(20) NOT NULL, reserved_m INT DEFAULT NULL, note LONGTEXT DEFAULT NULL, registered_at DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cable_type_id INT NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_7DB407A89978C35C (reel_number), INDEX IDX_7DB407A8E0B6D1E (cable_type_id), INDEX IDX_7DB407A8B03A8386 (created_by_id), INDEX IDX_7DB407A8896DBBDE (updated_by_id), INDEX idx_spool_reel (reel_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cable_spool_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, type VARCHAR(32) NOT NULL, visible_m INT DEFAULT NULL, used_meters INT DEFAULT NULL, project_label VARCHAR(255) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, spool_id INT NOT NULL, created_by_id INT DEFAULT NULL, corrects_event_id INT DEFAULT NULL, INDEX IDX_38C08B66A1A590BB (spool_id), INDEX IDX_38C08B66B03A8386 (created_by_id), INDEX IDX_38C08B665D4B7A45 (corrects_event_id), INDEX idx_event_spool_time (spool_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cable_type (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, full_description LONGTEXT DEFAULT NULL, family VARCHAR(32) NOT NULL, fiber_count INT NOT NULL, construction_code VARCHAR(32) DEFAULT NULL, diameter_mm NUMERIC(4, 1) DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_37A8D7377153098 (code), INDEX IDX_37A8D73B03A8386 (created_by_id), INDEX IDX_37A8D73896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8E0B6D1E FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)');
        $this->addSql('ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cable_spool_event ADD CONSTRAINT FK_38C08B66A1A590BB FOREIGN KEY (spool_id) REFERENCES cable_spool (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cable_spool_event ADD CONSTRAINT FK_38C08B66B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cable_spool_event ADD CONSTRAINT FK_38C08B665D4B7A45 FOREIGN KEY (corrects_event_id) REFERENCES cable_spool_event (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cable_type ADD CONSTRAINT FK_37A8D73B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cable_type ADD CONSTRAINT FK_37A8D73896DBBDE FOREIGN KEY (updated_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cable_spool DROP FOREIGN KEY FK_7DB407A8E0B6D1E');
        $this->addSql('ALTER TABLE cable_spool DROP FOREIGN KEY FK_7DB407A8B03A8386');
        $this->addSql('ALTER TABLE cable_spool DROP FOREIGN KEY FK_7DB407A8896DBBDE');
        $this->addSql('ALTER TABLE cable_spool_event DROP FOREIGN KEY FK_38C08B66A1A590BB');
        $this->addSql('ALTER TABLE cable_spool_event DROP FOREIGN KEY FK_38C08B66B03A8386');
        $this->addSql('ALTER TABLE cable_spool_event DROP FOREIGN KEY FK_38C08B665D4B7A45');
        $this->addSql('ALTER TABLE cable_type DROP FOREIGN KEY FK_37A8D73B03A8386');
        $this->addSql('ALTER TABLE cable_type DROP FOREIGN KEY FK_37A8D73896DBBDE');
        $this->addSql('DROP TABLE cable_spool');
        $this->addSql('DROP TABLE cable_spool_event');
        $this->addSql('DROP TABLE cable_type');
    }
}
