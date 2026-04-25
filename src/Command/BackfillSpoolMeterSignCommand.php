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

#[AsCommand(
    name: 'app:spool:backfill-meter-sign',
    description: 'Nastaví cable_spool.meter_sign podle prvního a konzistentního kroku v řetězci m (záznamy s čtením m). Přeskočí smíšené kroky a rozpor s uloženým znakem.',
)]
final class BackfillSpoolMeterSignCommand extends Command
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
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nic neukládat, jen vypsat souhrn');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        $ids = $this->spools->findIdsWithMeterReadingEvents();
        $c = [
            'updated' => 0,
            'unchanged' => 0,
            'skip_no_nonzero' => 0,
            'skip_mixed' => 0,
            'skip_conflicts_stored' => 0,
            'skip_no_chain' => 0,
        ];

        foreach ($ids as $id) {
            $spool = $this->spools->findOneWithEventsById($id);
            if (null === $spool) {
                continue;
            }
            $a = $this->meter->analyzeInferredMeterSignFromChain($spool);
            $st = $a['status'];
            if ('inferred' === $st) {
                if (!$dry) {
                    $spool->setMeterSign($a['inferred']);
                    $this->em->persist($spool);
                }
                ++$c['updated'];
            } else {
                match ($st) {
                    'unchanged' => ++$c['unchanged'],
                    'no_nonzero_step' => ++$c['skip_no_nonzero'],
                    'mixed_steps' => ++$c['skip_mixed'],
                    'conflicts_stored' => ++$c['skip_conflicts_stored'],
                    'no_chain' => ++$c['skip_no_chain'],
                    default => null,
                };
            }
        }

        if (!$dry) {
            $this->em->flush();
        }

        $io->text([
            'Cívek v řetězci m (kandidáti): '.\count($ids),
        ]);
        if ($dry) {
            $io->note('Režim dry-run: zápis do DB se neprovedl.');
        }
        $io->definitionList(
            ['Uloženo / aktualizováno' => (string) $c['updated']],
            ['Již odpovídá (zůstává)' => (string) $c['unchanged']],
            ['Přeskočeno: žádný nenulový krok' => (string) $c['skip_no_nonzero']],
            ['Přeskočeno: smíšené kroky v deníku' => (string) $c['skip_mixed']],
            ['Přeskočeno: rozpor s uloženým meter_sign' => (string) $c['skip_conflicts_stored']],
            ['Přeskočeno: žádný řetězec' => (string) $c['skip_no_chain']],
        );

        return Command::SUCCESS;
    }
}
