<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'warehouse_project_report_alias: sloučení variant názvu zakázky pro reporty.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE warehouse_project_report_alias (id INT AUTO_INCREMENT NOT NULL, canonical_label VARCHAR(255) NOT NULL, alias_label VARCHAR(255) NOT NULL, alias_normalized VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_project_report_alias_norm (alias_normalized), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE warehouse_project_report_alias');
    }
}
