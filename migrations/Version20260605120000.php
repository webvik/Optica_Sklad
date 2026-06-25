<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user: deactivation_message (text při pokusu o přihlášení k deaktivovanému účtu).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD deactivation_message LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP deactivation_message');
    }
}
