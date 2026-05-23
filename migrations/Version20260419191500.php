<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Přidá sloupec app_user.phone (volitelný kontakt na WhatsApp apod.).';
    }

    public function up(Schema $schema): void
    {
        // Na čisté DB běží dřív než Version20260425100103 (CREATE app_user) — přeskočit, sloupec doplní později stejná migrace po vytvoření tabulky.
        if (!$schema->hasTable('app_user')) {
            return;
        }
        if ($schema->getTable('app_user')->hasColumn('phone')) {
            return;
        }

        $this->addSql('ALTER TABLE app_user ADD phone VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('app_user') || !$schema->getTable('app_user')->hasColumn('phone')) {
            return;
        }

        $this->addSql('ALTER TABLE app_user DROP phone');
    }
}
