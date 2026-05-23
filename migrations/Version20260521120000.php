<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool.correction_note: poznámka ke korekci u příznaku needs_correction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool ADD correction_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool DROP correction_note');
    }
}
