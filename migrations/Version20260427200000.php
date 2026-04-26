<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Katalog typů/řad kabelu (cable_family) pro filtr na formuláři nové cívky.
 */
final class Version20260427200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_family: katalog řad (blown, mlt, drop, fletka, …).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cable_family (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(32) NOT NULL, label VARCHAR(128) NOT NULL, description LONGTEXT DEFAULT NULL, sort_order INT NOT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_CABLE_FAMILY_CODE (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("INSERT INTO cable_family (id, code, label, description, sort_order, is_active, created_at) VALUES
            (1, 'blown', 'blown (ofuk)', 'Konstrukce typu blown; podrobný popis můžete doplňovat v administraci.', 10, 1, NOW()),
            (2, 'mlt', 'mlt (multi-loose-tube)', NULL, 20, 1, NOW()),
            (3, 'drop', 'drop (drop kabel)', NULL, 30, 1, NOW()),
            (4, 'fletka', 'fletka (flat drop)', NULL, 40, 1, NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cable_family');
    }
}
