<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zajistí, že cable_spool.cable_type_id je NULLable (doplnění, pokud Version20260428190000
 * neběžela nebo selhala). Při již null sloupci nic nedělá.
 */
final class Version20260429120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool.cable_type_id: nullable (idempotent, název FK z information_schema).';
    }

    public function up(Schema $schema): void
    {
        $c = $this->connection;
        if (!$c->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Přeskočeno: pouze MySQL / MariaDB.');

            return;
        }
        $nullInfo = $c->fetchOne(
            "SELECT c.IS_NULLABLE FROM information_schema.COLUMNS c
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'cable_spool' AND c.COLUMN_NAME = 'cable_type_id'"
        );
        if (false === $nullInfo) {
            $this->write('Sloupec cable_type_id nenalezen, přeskočeno.');

            return;
        }
        if ('YES' === $nullInfo) {
            $this->write('cable_type_id je již NULLable – přeskočeno.');

            return;
        }
        $fk = $c->fetchOne(
            "SELECT k.CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE k
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.TABLE_NAME = 'cable_spool'
               AND k.COLUMN_NAME = 'cable_type_id'
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1"
        );
        if (null !== $fk && false !== $fk) {
            $c->executeStatement('ALTER TABLE cable_spool DROP FOREIGN KEY `'.$this->backtickName((string) $fk).'`');
        }
        $c->executeStatement('ALTER TABLE cable_spool CHANGE cable_type_id cable_type_id INT DEFAULT NULL');
        if (null !== $fk && false !== $fk) {
            $c->executeStatement(
                'ALTER TABLE cable_spool ADD CONSTRAINT `'.$this->backtickName((string) $fk).'` '
                .'FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)'
            );
        } else {
            $c->executeStatement(
                'ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8E0B6D1E '
                .'FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)'
            );
        }
    }

    public function down(Schema $schema): void
    {
    }

    private function backtickName(string $name): string
    {
        return str_replace('`', '``', $name);
    }
}
