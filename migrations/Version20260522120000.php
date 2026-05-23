<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool: archiv vyřízené korekce (resolved_at, resolved_note).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool ADD correction_resolved_at DATETIME DEFAULT NULL, ADD correction_resolved_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool DROP correction_resolved_at, DROP correction_resolved_note');
    }
}
