<?php

namespace App\Command;

use App\Entity\CableType;
use App\Entity\Spool;
use App\Enum\SpoolStatus;
use App\Repository\CableTypeRepository;
use App\Repository\SpoolRepository;
use App\Service\Warehouse\InventuraBriefGroupLabel;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * XLSX columns through poc_vidl_m_ps → cable_type + cable_spool.
 * (usek* columns in file are not imported here.)
 */
#[AsCommand(
    name: 'app:import-spools-xlsx',
    description: 'Naimportuje cívky a typy kabelů z Přehled xlsx (sloupce do poc_vidl_m_ps včetně).',
)]
final class ImportSpoolsFromXlsxCommand extends Command
{
    private const EXPECTED = [
        'evidencni_cislo',
        'kod_zasoby',
        'nazev',
        'stav_m',
        'rezervovano_m',
        'note',
        'pocet_vlaken',
        'prumer_mm',
        'technologie',
        'poc_vidl_m_ps',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CableTypeRepository $cableTypeRepository,
        private readonly SpoolRepository $spoolRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Cesta k .xlsx (default: Optica_Sklad_Doc/Přehled_kabelových_cívek_import_enriched.xlsx u projektu bratrů)',
            )
            ->addOption('update', null, InputOption::VALUE_NONE, 'Aktualizovat existující cívky (dle ev. čísla) místo přeskočit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $this->resolvePath($input->getArgument('path'));
        if (!is_file($path) || !is_readable($path)) {
            $io->error('Soubor není čitelný: '.$path);

            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rowIterator = $sheet->getRowIterator(1, 1);
        $rowIterator->rewind();
        $headRow = $rowIterator->current();
        if (null === $headRow) {
            $io->error('Prázdný list.');

            return self::FAILURE;
        }

        $head = [];
        foreach ($headRow->getCellIterator() as $c) {
            $v = $c->getValue();
            if (null !== $v && '' !== (string) $v) {
                $head[] = (string) $v;
            }
        }

        foreach (self::EXPECTED as $h) {
            if (!\in_array($h, $head, true)) {
                $io->error("V hlavičce chybí sloupec: {$h}");

                return self::FAILURE;
            }
        }

        $colIndex = [];
        foreach (self::EXPECTED as $name) {
            $colIndex[$name] = \array_search($name, $head, true) + 1;
        }

        $highestRow = (int) $sheet->getHighestDataRow();
        $doUpdate = (bool) $input->getOption('update');
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $typesCreated = 0;
        /** @var array<string, CableType> */
        $cableByCode = [];

        for ($r = 2; $r <= $highestRow; ++$r) {
            $reel = $this->strCell($sheet, $r, $colIndex['evidencni_cislo']);
            if ('' === $reel) {
                continue;
            }
            $kod = $this->strCell($sheet, $r, $colIndex['kod_zasoby']);
            if ('' === $kod) {
                $io->warning("Řádek {$r}: prázdný kod_zasoby, přeskočeno ({$reel})");

                continue;
            }

            if (isset($cableByCode[$kod])) {
                $cable = $cableByCode[$kod];
            } else {
                $cable = $this->cableTypeRepository->findOneBy(['code' => $kod]);
                if (null === $cable) {
                    $cable = $this->makeCableType(
                        $kod,
                        $this->strCell($sheet, $r, $colIndex['nazev']),
                        $this->strCell($sheet, $r, $colIndex['technologie']),
                        $this->intOrDefault($sheet, $r, $colIndex['pocet_vlaken'], 1),
                        $this->strCell($sheet, $r, $colIndex['prumer_mm']),
                    );
                    $this->em->persist($cable);
                    ++$typesCreated;
                }
                $cableByCode[$kod] = $cable;
            }

            $spool = $this->spoolRepository->findOneBy(['reelNumber' => $reel]);
            if (null !== $spool && !$doUpdate) {
                ++$skipped;
                continue;
            }

            $stavM = $this->intOrDefault($sheet, $r, $colIndex['stav_m'], 0);
            $pocPs = $this->optionalInt($sheet, $r, $colIndex['poc_vidl_m_ps']);
            if (null === $pocPs) {
                $pocPs = 0;
            }
            $res = $this->intOrDefault($sheet, $r, $colIndex['rezervovano_m'], 0);
            // total_length_m = fyzická délka kabelu na cívce (m). Stav_m je zůstatek; poc_vidl_m_ps je číslo
            // na metru u kabelu (jiná škála) — nikdy ho nemíchat do total (max(stav, poč.m) dá třeba 8400 místo 2094).
            $lengthFromStav = \max(1, $stavM);
            if (null === $spool) {
                $total = $lengthFromStav;
            } else {
                $oldTotal = $spool->getTotalLengthM();
                $oldIni = $spool->getInitialVisibleM();
                // Dřívější chyba: total = max(stav, poc_m) — total se shodoval s číslem na metru (stejné jako initial_visible_m).
                if ($oldTotal === $oldIni && $oldIni > 0 && $lengthFromStav < $oldTotal
                    && $oldTotal > 2 * $lengthFromStav) {
                    $total = $lengthFromStav;
                } else {
                    $total = \max($oldTotal, $lengthFromStav);
                }
            }
            $note = $this->optionalStr($sheet, $r, $colIndex['note']);
            $fiber = $this->intOrDefault($sheet, $r, $colIndex['pocet_vlaken'], 1);
            $diam = $this->normalizeDecimal($this->strCell($sheet, $r, $colIndex['prumer_mm']));

            if (null === $spool) {
                $spool = new Spool();
                $spool->setReelNumber($reel);
            }
            $spool->setCableType($cable);
            $spool->setTotalLengthM($total);
            $spool->setInitialVisibleM($pocPs);
            $spool->setCurrentRemainingM($stavM);
            $spool->setLastVisibleM($pocPs);
            $spool->setMeterSign(null);
            $spool->setFiberCount($fiber);
            if ('' === $diam) {
                $spool->setDiameterMm(null);
            } else {
                $spool->setDiameterMm($diam);
            }
            $spool->setStatus(SpoolStatus::InStock);
            $spool->setReservedM($res);
            $spool->setNote('' === $note ? null : $note);
            $spool->setRegisteredAt(new \DateTimeImmutable());
            if (null === $spool->getId()) {
                $this->em->persist($spool);
                ++$imported;
            } else {
                ++$updated;
            }
        }

        $this->em->flush();

        $io->success(\sprintf(
            'Hotovo: +%d nových cívek, %d upraveno, %d přeskočeno (stejné ev.č.), +%d nových typů kabelu.',
            $imported,
            $updated,
            $skipped,
            $typesCreated,
        ));

        return self::SUCCESS;
    }

    private function resolvePath(?string $arg): string
    {
        if (null !== $arg && '' !== $arg) {
            return $arg;
        }
        $sibling = \dirname($this->getProjectDir(), 1).'/Optica_Sklad_Doc/Přehled_kabelových_cívek_import_enriched.xlsx';

        return $sibling;
    }

    private function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function makeCableType(
        string $code,
        string $nazev,
        string $family,
        int $fiberCount,
        string $prumer,
    ): CableType {
        $ct = new CableType();
        $ct->setCode($code);
        $ct->setFullDescription('' === $nazev ? null : $nazev);
        $ct->setFamily('' === $family ? 'unknown' : $family);
        $ct->setFiberCount($fiberCount);
        if (1 === \preg_match('/(Z[0-9A-Z\\d]+|TM[0-9A-Z]+I)$/i', $code, $m)) {
            $ct->setConstructionCode($m[1]);
        }
        $d = $this->normalizeDecimal($prumer);
        if ('' !== $d) {
            $ct->setDiameterMm($d);
        }
        $ct->setIsActive(true);
        $short = \mb_substr(InventuraBriefGroupLabel::forCableType($ct), 0, 255);
        if ('' === \trim($short)) {
            $short = $code;
        }
        $ct->setName($short);

        return $ct;
    }

    private function strCell($sheet, int $row, int $col): string
    {
        $addr = Coordinate::stringFromColumnIndex($col).$row;
        $v = $sheet->getCell($addr)->getValue();

        return null === $v ? '' : \trim((string) $v);
    }

    private function optionalStr($sheet, int $row, int $col): string
    {
        return $this->strCell($sheet, $row, $col);
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

    private function intOrDefault($sheet, int $row, int $col, int $default): int
    {
        $i = $this->optionalInt($sheet, $row, $col);

        return $i ?? $default;
    }

    private function normalizeDecimal(string $raw): string
    {
        $s = \trim($raw);
        if ('' === $s) {
            return '';
        }
        $s = \str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $s);
        if (!\is_numeric($s)) {
            return '';
        }
        $f = (float) $s;

        return (string) \round($f, 1);
    }
}
