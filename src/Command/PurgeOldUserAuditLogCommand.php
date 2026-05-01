<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserAuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user-audit:purge-old',
    description: 'Smazání záznamů z user_audit_log starších než N dní (retence kvůli objemu dat). Cron: viz --help.',
)]
final class PurgeOldUserAuditLogCommand extends Command
{
    public function __construct(
        private readonly UserAuditLogRepository $userAuditLogs,
        private readonly int $defaultRetentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Záznamy starší než tento počet dní.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Jen vypsat počet záznamů ke smazání, bez mazání.',
            )
            ->setHelp(
                <<<TXT
Úplný řádek v user_audit_log (včetně volitelných POST polí) — retence jen mazáním řádků.

Výchozí počet dní bere proměnnou USER_AUDIT_LOG_RETENTION_DAYS; jinak lze použít --days.

Cron (jednou denně 4:07, uprav uživatele/c):

  7 4 * * * cd /home/httpd/html/lowpartners.net && php84 bin/console app:user-audit:purge-old --env=prod --no-ansi >> /tmp/optica-user-audit-purge.log 2>&1

Test bez mazání:

  php84 bin/console app:user-audit:purge-old --dry-run --env=prod
TXT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysOpt = $input->getOption('days');
        $days = null !== $daysOpt ? (int) $daysOpt : $this->defaultRetentionDays;

        if ($days < 1) {
            $io->error('Parametr days musí být ≥ 1.');

            return Command::FAILURE;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));
        $count = $this->userAuditLogs->countOlderThan($cutoff);

        if (0 === $count) {
            $io->success(sprintf(
                'Nic ke smazání (cutoff %s, starší než %d dní).',
                $cutoff->format('Y-m-d H:i:s'),
                $days,
            ));

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf(
                'Dry-run: %d záznamů by se smazalo (cutoff occurred_at < %s, retence %d dní).',
                $count,
                $cutoff->format('Y-m-d H:i:s'),
                $days,
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->userAuditLogs->deleteOlderThan($cutoff);
        $io->success(sprintf(
            'Smazáno %d záznamů z user_audit_log (occurred_at < %s).',
            $deleted,
            $cutoff->format('Y-m-d H:i:s'),
        ));

        return Command::SUCCESS;
    }
}
