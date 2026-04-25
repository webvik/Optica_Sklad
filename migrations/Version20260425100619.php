<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User: вход по username; email — опционально; имя/фамилия.
 * Старые строки: username = часть e-mail до @ + '-' + id (уникально), либо user{id}.
 */
final class Version20260425100619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user: username, optional email, first/last name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD username VARCHAR(180) DEFAULT NULL, ADD first_name VARCHAR(100) DEFAULT NULL, ADD last_name VARCHAR(100) DEFAULT NULL, CHANGE email email VARCHAR(180) DEFAULT NULL');
        $this->addSql(<<<'SQL'
UPDATE app_user
SET username = LOWER(
    CONCAT(
        IFNULL(
            NULLIF(
                TRIM(
                    SUBSTRING_INDEX(COALESCE(NULLIF(email, ''), CONCAT('id', id, '@t')), '@', 1)
                ),
                ''
            ),
            'user'
        ),
        '-',
        id
    )
)
SQL);
        $this->addSql('ALTER TABLE app_user MODIFY username VARCHAR(180) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9F85E0677 ON app_user (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_88BDF3E9F85E0677 ON app_user');
        $this->addSql('ALTER TABLE app_user DROP username, DROP first_name, DROP last_name, CHANGE email email VARCHAR(180) NOT NULL');
    }
}
