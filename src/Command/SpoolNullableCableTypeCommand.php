<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jednorázová oprava schématu, pokud migrace s NULLable cable_type_id neproběhly
 * a aplikace padá na „Column 'cable_type_id' cannot be null“.
 */
#[AsCommand(
    name: 'warehouse:spool-nullable-cable-type',
    description: 'MySQL: cable_spool.cable_type_id umožní NULL (stejné jako odpovídající migrace)',
)]
final class SpoolNullableCableTypeCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $c = $this->connection;
        if (!$c->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $io->error('Pouze MySQL nebo MariaDB.');

            return self::FAILURE;
        }
        $nullInfo = $c->fetchOne(
            "SELECT c.IS_NULLABLE FROM information_schema.COLUMNS c
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'cable_spool' AND c.COLUMN_NAME = 'cable_type_id'"
        );
        if (false === $nullInfo) {
            $io->error('Tabulka/sloupec cable_spool.cable_type_id nenalezen.');

            return self::FAILURE;
        }
        if ('YES' === $nullInfo) {
            $io->success('cable_type_id je již NULLable — nic není třeba měnit.');

            return self::SUCCESS;
        }
        $io->note('Upravuji sloupec cable_type_id na NULLable…');
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
            $q = (string) $fk;
            $c->executeStatement('ALTER TABLE cable_spool DROP FOREIGN KEY `'.$this->escIdent($q).'`');
            $io->text('Odebrán cizí klíč: `'.$q.'`');
        } else {
            $io->warning('FK pro cable_type_id v information_schema nenalezen; zkouším pouze úpravu sloupce.');
        }
        $c->executeStatement('ALTER TABLE cable_spool CHANGE cable_type_id cable_type_id INT DEFAULT NULL');
        if (null !== $fk && false !== $fk) {
            $q = (string) $fk;
            $c->executeStatement(
                'ALTER TABLE cable_spool ADD CONSTRAINT `'.$this->escIdent($q).'` '
                .'FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)'
            );
            $io->text('Obnoven cizí klíč: `'.$q.'`');
        } else {
            $c->executeStatement(
                'ALTER TABLE cable_spool ADD CONSTRAINT FK_7DB407A8E0B6D1E '
                .'FOREIGN KEY (cable_type_id) REFERENCES cable_type (id)'
            );
            $io->text('Přidán cizí klíč FK_7DB407A8E0B6D1E (původní jméno nebylo dohledatelné).');
        }
        $io->success('Hotovo. Zkuste znovu uložit cívku bez typu kabelu.');

        return self::SUCCESS;
    }

    private function escIdent(string $s): string
    {
        return str_replace('`', '``', $s);
    }
}
