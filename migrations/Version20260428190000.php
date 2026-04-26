<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Typ kabelu u cívky může být dočasně nevyplněný (doplní se později).
 */
final class Version20260428190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool.cable_type_id: nullable FK (volitelný typ při zaevidování).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool DROP FOREIGN KEY FK_7DB407A8E0B6D1E');
        $this->addSql('ALTER TABLE cable_spool CHANGE cable_type_id cable_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8E0B6D1E FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool DROP FOREIGN KEY FK_7DB407A8E0B6D1E');
        $this->addSql('UPDATE cable_spool SET cable_type_id = (SELECT id FROM cable_type LIMIT 1) WHERE cable_type_id IS NULL');
        $this->addSql('ALTER TABLE cable_spool CHANGE cable_type_id cable_type_id INT NOT NULL');
        $this->addSql('ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8E0B6D1E FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)');
    }
}
