<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool: warehouse_card_printed_at (skladová karta vytištěna / odeslána).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool ADD warehouse_card_printed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool DROP warehouse_card_printed_at');
    }
}
