<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Archiv skenů dodacích listů (stránky na disku + metadata). */
final class Version20260718140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulky dodaci_list + dodaci_list_page (archiv skenů).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dodaci_list (id INT AUTO_INCREMENT NOT NULL, document_number VARCHAR(64) DEFAULT NULL, document_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, note LONGTEXT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_dodaci_list_doc_date (document_date), INDEX IDX_DODACI_LIST_CREATED_BY (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dodaci_list_page (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, original_filename VARCHAR(255) NOT NULL, storage_path VARCHAR(512) NOT NULL, mime_type VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, dodaci_list_id INT NOT NULL, INDEX IDX_DODACI_LIST_PAGE_LIST (dodaci_list_id), UNIQUE INDEX uniq_dodaci_list_page_pos (dodaci_list_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE dodaci_list ADD CONSTRAINT FK_DODACI_LIST_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dodaci_list_page ADD CONSTRAINT FK_DODACI_LIST_PAGE_LIST FOREIGN KEY (dodaci_list_id) REFERENCES dodaci_list (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dodaci_list_page DROP FOREIGN KEY FK_DODACI_LIST_PAGE_LIST');
        $this->addSql('ALTER TABLE dodaci_list DROP FOREIGN KEY FK_DODACI_LIST_CREATED_BY');
        $this->addSql('DROP TABLE dodaci_list_page');
        $this->addSql('DROP TABLE dodaci_list');
    }
}
