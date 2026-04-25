<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill: při příjmu je PS = initial_visible_m; stejná hodnota patří i do last_visible_m,
 * dokud neproběhne první odběr dle metru (jako initNewSpoolState).
 */
final class Version20260426120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool: set last_visible_m = initial_visible_m where last_visible_m IS NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE cable_spool SET last_visible_m = initial_visible_m WHERE last_visible_m IS NULL');
    }

    public function down(Schema $schema): void
    {
        // Beze ztráty dat nelze odlišit tento backfill od řádků, kde uživatel reálně má last = initial.
    }
}
