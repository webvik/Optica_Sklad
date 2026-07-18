<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nerozbalené cívky: nullable saře / PS (status received_sealed je jen hodnota ENUM ve VARCHAR).
 */
final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool: reel_number a initial_visible_m nullable (příjem nerozbalených cívek).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool CHANGE reel_number reel_number VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE cable_spool CHANGE initial_visible_m initial_visible_m INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cable_spool SET reel_number = CONCAT('#', id) WHERE reel_number IS NULL");
        $this->addSql('UPDATE cable_spool SET initial_visible_m = 0 WHERE initial_visible_m IS NULL');
        $this->addSql('ALTER TABLE cable_spool CHANGE reel_number reel_number VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE cable_spool CHANGE initial_visible_m initial_visible_m INT NOT NULL');
    }
}
