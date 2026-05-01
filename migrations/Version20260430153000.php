<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_audit_log; prázdné role app_user → EDIT; garantovaný ROLE_APP_ADMIN při absenci.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->write('Skipping user_audit_log / role bootstrap SQL: unsupported platform.');

            return;
        }

        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS user_audit_log (
    id INT AUTO_INCREMENT NOT NULL,
    occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    user_id INT DEFAULT NULL,
    username VARCHAR(180) NOT NULL,
    roles_snapshot JSON DEFAULT NULL COMMENT '(DC2Type:json)',
    method VARCHAR(8) NOT NULL,
    path VARCHAR(2048) NOT NULL,
    route_name VARCHAR(120) DEFAULT NULL,
    http_status SMALLINT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent_fragment VARCHAR(512) DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX idx_user_audit_log_occurred (occurred_at),
    INDEX idx_user_audit_log_username (username),
    CONSTRAINT fk_user_audit_app_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
    }

    public function postUp(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        try {
            $rows = $this->connection->fetchAllAssociative('SELECT id, roles FROM app_user');
        } catch (\Throwable) {
            return;
        }

        $anyAppAdmin = false;
        foreach ($rows as $row) {
            $decoded = \json_decode((string) ($row['roles'] ?? '[]'), true);
            if (!\is_array($decoded)) {
                $decoded = [];
            }
            if (\in_array('ROLE_APP_ADMIN', $decoded, true)) {
                $anyAppAdmin = true;

                break;
            }
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $decoded = \json_decode((string) ($row['roles'] ?? '[]'), true);
            if (!\is_array($decoded)) {
                $decoded = [];
            }
            if ($decoded === []) {
                $this->connection->executeStatement(
                    'UPDATE app_user SET roles = ? WHERE id = ?',
                    [\json_encode(['ROLE_SKLAD_EDIT'], \JSON_THROW_ON_ERROR), $id],
                );
            }
        }

        if (!$anyAppAdmin) {
            $minId = $this->connection->fetchOne('SELECT MIN(id) FROM app_user');
            if (null !== $minId && '' !== (string) $minId) {
                $this->connection->executeStatement(
                    'UPDATE app_user SET roles = ? WHERE id = ?',
                    [\json_encode(['ROLE_APP_ADMIN'], \JSON_THROW_ON_ERROR), (int) $minId],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $this->addSql('DROP TABLE IF EXISTS user_audit_log');
    }
}
