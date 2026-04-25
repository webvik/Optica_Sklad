<?php

namespace App\Command;

use App\Entity\SpoolEvent;
use App\Enum\SpoolEventType;
use App\Repository\SpoolRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * usek1_m / usek1_text … usek6_* → SpoolEvent (laid_section).
 */
#[AsCommand(
    name: 'app:import-spool-usek-xlsx',
    description: 'Zápis úseků ze štítku (usekN_m, usekN_text) do cable_spool_event jako typ „úsek (štítek)“.',
)]
final class ImportSpoolUsekEventsCommand extends Command
{
    private const USEK_N = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpoolRepository $spoolRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Cesta k .xlsx (default stejná jako u app:import-spools-xlsx)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $this->resolvePath($input->getArgument('path'));
        if (!\is_file($path) || !\is_readable($path)) {
            $io->error('Soubor není čitelný: '.$path);

            return self::FAILURE;
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $row1 = $sheet->getRowIterator(1, 1)->current();
        if (null === $row1) {
            $io->error('Prázdný list.');

            return self::FAILURE;
        }
        $head = [];
        foreach ($row1->getCellIterator() as $c) {
            $v = $c->getValue();
            if (null !== $v && '' !== (string) $v) {
                $head[] = (string) $v;
            }
        }
        if (!\in_array('evidencni_cislo', $head, true)) {
            $io->error('V hlavičce chybí sloupec evidencni_cislo.');

            return self::FAILURE;
        }
        for ($i = 1; $i <= self::USEK_N; ++$i) {
            if (!\in_array("usek{$i}_m", $head, true)) {
                $io->error("V hlavičce chybí usek{$i}_m");

                return self::FAILURE;
            }
            if (null === $this->usekTextCol($head, $i)) {
                $io->error("V hlavičce chybí usek{$i}_text (nebo usek{$i}_tex).");

                return self::FAILURE;
            }
        }

        $evidCol = \array_search('evidencni_cislo', $head, true) + 1;
        $mCols = [];
        $tCols = [];
        for ($i = 1; $i <= self::USEK_N; ++$i) {
            $mCols[$i] = \array_search("usek{$i}_m", $head, true) + 1;
            $tCols[$i] = $this->usekTextCol($head, $i);
        }

        $highestRow = (int) $sheet->getHighestDataRow();
        $spoolsTouched = 0;
        $eventsCreated = 0;
        $rowsEmpty = 0;
        $missingSpool = 0;

        for ($r = 2; $r <= $highestRow; ++$r) {
            $reel = $this->strCell($sheet, $r, $evidCol);
            if ('' === $reel) {
                continue;
            }
            $segments = [];
            for ($i = 1; $i <= self::USEK_N; ++$i) {
                $m = $this->optionalInt($sheet, $r, $mCols[$i]);
                $t = $this->strCell($sheet, $r, $tCols[$i]);
                if (null === $m && '' === $t) {
                    continue;
                }
                $segments[] = ['i' => $i, 'm' => $m, 'text' => $t];
            }
            if ($segments === []) {
                ++$rowsEmpty;
                continue;
            }
            $spool = $this->spoolRepository->findOneBy(['reelNumber' => $reel]);
            if (null === $spool) {
                $io->warning("Cívka nenalezena (ev. č.): {$reel} — řádek {$r} přeskočeno.");
                ++$missingSpool;
                continue;
            }

            $this->em->createQueryBuilder()
                ->delete(SpoolEvent::class, 'e')
                ->where('e.spool = :s')
                ->andWhere('e.type = :t')
                ->setParameter('s', $spool)
                ->setParameter('t', SpoolEventType::LaidSection)
                ->getQuery()
                ->execute();

            $reg = $spool->getRegisteredAt() ?? new \DateTimeImmutable('today');
            $base = $reg->setTime(12, 0, 0);
            $ord = 0;
            foreach ($segments as $seg) {
                $at = $base->add(new \DateInterval('PT'.(60 * $ord).'S'));
                ++$ord;
                $ev = new SpoolEvent();
                $ev->setSpool($spool);
                $ev->setType(SpoolEventType::LaidSection);
                $ev->setOccurredAt($at);
                $ev->setVisibleM($seg['m']);
                $ev->setUsedMeters(null);
                $tx = $seg['text'];
                $ev->setProjectLabel('' === $tx ? null : $tx);
                $this->em->persist($ev);
                ++$eventsCreated;
            }
            ++$spoolsTouched;
        }
        $this->em->flush();

        $io->success(\sprintf(
            'Hotovo: %d cívek aktualizováno, +%d událostí (úsek/štítek), %d řádků bez úseku, %d chybějících cívek.',
            $spoolsTouched,
            $eventsCreated,
            $rowsEmpty,
            $missingSpool,
        ));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $head
     */
    private function usekTextCol(array $head, int $i): ?int
    {
        foreach (["usek{$i}_text", "usek{$i}_tex"] as $name) {
            if (\in_array($name, $head, true)) {
                return \array_search($name, $head, true) + 1;
            }
        }

        return null;
    }

    private function resolvePath(?string $arg): string
    {
        if (null !== $arg && '' !== $arg) {
            return $arg;
        }
        $project = \dirname(__DIR__, 2);

        return \dirname($project, 1).'/Optica_Sklad_Doc/Přehled_kabelových_cívek_import_enriched.xlsx';
    }

    private function strCell($sheet, int $row, int $col): string
    {
        $addr = Coordinate::stringFromColumnIndex($col).$row;
        $v = $sheet->getCell($addr)->getValue();

        return null === $v ? '' : \trim((string) $v);
    }

    private function optionalInt($sheet, int $row, int $col): ?int
    {
        $s = $this->strCell($sheet, $row, $col);
        if ('' === $s) {
            return null;
        }
        if (1 === \preg_match('/^[+-]?\d+([.,]\d+)?$/', $s, $m)) {
            $s = \str_replace(',', '.', $s);
        } else {
            if (1 !== \preg_match('/[+-]?\d+/', $s, $m)) {
                return null;
            }
            $s = $m[0];
        }

        return (int) \round((float) $s);
    }
}
