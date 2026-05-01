<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430153100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_audit_log: volitelný JSON s redigovanými poli POST formuláře (AUDIT_LOG_FORM_FIELDS).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping post_payload_redacted: unsupported platform.');

            return;
        }

        $this->addSql('ALTER TABLE user_audit_log ADD post_payload_redacted JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->addSql('ALTER TABLE user_audit_log DROP post_payload_redacted');
    }
}
