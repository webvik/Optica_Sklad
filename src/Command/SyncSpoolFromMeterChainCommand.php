<?php

namespace App\Command;

use App\Repository\SpoolRepository;
use App\Service\Warehouse\SpoolMeterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronizuje cable_spool_event.used_meters a stav cívky z řetězce m (kroky
 * odběr/úsek) — stejné jako při postupném ukládání.
 */
#[AsCommand(
    name: 'app:spool:sync-from-meter-chain',
    description: 'Přepočet used_meters v událostech, current_remaining_m, last_visible_m, meter_sign z dat visible_m a počátečních hodnot.',
)]
final class SyncSpoolFromMeterChainCommand extends Command
{
    public function __construct(
        private readonly SpoolRepository $spools,
        private readonly SpoolMeterService $meter,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen výpis, neukládej do DB');
        $this->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Jen jedno ID cívky', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $onlyId = $input->getOption('id');

        $ids = null !== $onlyId && '' !== (string) $onlyId
            ? [(int) $onlyId]
            : $this->spools->findIdsWithMeterReadingEvents();

        $fixed = 0;
        $allWarnings = [];

        foreach ($ids as $id) {
            $spool = $this->spools->findOneWithEventsById($id);
            if (null === $spool) {
                continue;
            }
            $r = $this->meter->recomputeSpoolStateFromMeterEvents($spool, !$dry, true);
            $fixed += $r['eventUsedMetersFixed'];
            foreach ($r['warnings'] as $w) {
                $allWarnings[] = "cívka #{$id}: {$w}";
            }
        }

        if (!$dry) {
            $this->em->flush();
        }

        $io->text(\sprintf('Cívek zpracováno (kandidátů s řetězcem m): %d', \count($ids)));
        $io->text(\sprintf('Upravených záznamů used_meters: %d', $fixed));
        if ($allWarnings !== []) {
            foreach ($allWarnings as $w) {
                $io->warning($w);
            }
        }
        if ($dry) {
            $io->note('Dry-run: žádný zápis do DB.');
        } else {
            $io->success('Hotovo.');
        }

        return self::SUCCESS;
    }
}
