<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool.needs_correction: příznak cívky ke korekci (filtr Přehled skladu).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool ADD needs_correction TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool DROP needs_correction');
    }
}
