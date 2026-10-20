<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Fotky cívek (max. 3 na cívku, soubory ve var/spool_photo). */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka cable_spool_photo (až 3 fotky na cívku).';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cable_spool'])) {
            $cols = $sm->listTableColumns('cable_spool');
            if (isset($cols['photo_path']) || isset($cols['photo_mime'])) {
                $drops = [];
                if (isset($cols['photo_path'])) {
                    $drops[] = 'DROP photo_path';
                }
                if (isset($cols['photo_mime'])) {
                    $drops[] = 'DROP photo_mime';
                }
                $this->addSql('ALTER TABLE cable_spool '.\implode(', ', $drops));
            }
        }

        if (!$sm->tablesExist(['cable_spool_photo'])) {
            $this->addSql('CREATE TABLE cable_spool_photo (id INT AUTO_INCREMENT NOT NULL, storage_path VARCHAR(512) NOT NULL, mime_type VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, created_at DATETIME NOT NULL, spool_id INT NOT NULL, INDEX idx_spool_photo_spool_created (spool_id, created_at), INDEX IDX_SPOOL_PHOTO_SPOOL (spool_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE cable_spool_photo ADD CONSTRAINT FK_SPOOL_PHOTO_SPOOL FOREIGN KEY (spool_id) REFERENCES cable_spool (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool_photo DROP FOREIGN KEY FK_SPOOL_PHOTO_SPOOL');
        $this->addSql('DROP TABLE cable_spool_photo');
    }
}
