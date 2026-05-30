<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export skladové karty cívky do .xlsx z šablony (layout jako papírová karta).
 * Jeden list, dvě tiskové stránky (duplex): řádky 1–25 + 26–48 (ř. 26 = hlavička deníku zadní strany).
 */
final class SkladovaKartaExcelExporter
{
    private const TEMPLATE_REL = '/deploy/excel/skladova-karta.template.xlsx';

    /** Deník — přední strana (pod hlavičkou karty). */
    private const DIARY_PAGE1_FIRST_ROW = 9;

    private const DIARY_PAGE1_LAST_ROW = 25;

    /** Deník — zadní strana (řádek 26 = popisky sloupců ve šabloně). */
    private const DIARY_PAGE2_FIRST_ROW = 27;

    private const DIARY_PAGE2_LAST_ROW = 48;

    /** Formát data na kartě (bez času). */
    private const DATE_FORMAT = 'dd.mm.yyyy';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly SkladovaKartaDataBuilder $dataBuilder,
    ) {
    }

    /**
     * @return array{response: Response, truncated: bool, diaryRows: int}
     */
    public function download(Spool $spool): array
    {
        $path = $this->projectDir.self::TEMPLATE_REL;
        if (!is_readable($path)) {
            throw new \RuntimeException('Chybí šablona skladové karty: '.$path);
        }

        $karta = $this->dataBuilder->build($spool);

        $spreadsheet = IOFactory::load($path);
        $this->keepSingleSheet($spreadsheet);
        $ws = $spreadsheet->getActiveSheet();
        $this->ensureDuplexPrintLayout($ws);

        $perPage = SkladovaKartaDataBuilder::MAX_DIARY_ROWS_PER_PAGE;
        $diaryRows = $karta['diaryRows'];
        $page1Rows = \array_slice($diaryRows, 0, $perPage);
        $page2Rows = \array_slice($diaryRows, $perPage, SkladovaKartaDataBuilder::MAX_DIARY_ROWS_PAGE2);

        $this->fillHeader($ws, $spool, $karta);
        $this->clearDiaryDataAreas($ws);
        $this->fillDiaryBlock($ws, self::DIARY_PAGE1_FIRST_ROW, $page1Rows);
        $this->fillDiaryBlock($ws, self::DIARY_PAGE2_FIRST_ROW, $page2Rows);

        $filename = $this->filename($spool);

        return [
            'response' => $this->stream($spreadsheet, $filename),
            'truncated' => $karta['truncated'],
            'diaryRows' => \count($diaryRows),
        ];
    }

    private function keepSingleSheet(Spreadsheet $spreadsheet): void
    {
        while ($spreadsheet->getSheetCount() > 1) {
            $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
        }
    }

    /** Jeden list, zlom stránky po ř. 25 → ř. 26 (hlavička deníku) začíná stranu 2. */
    private function ensureDuplexPrintLayout(Worksheet $ws): void
    {
        $ps = $ws->getPageSetup();
        $ps->setFitToPage(false);
        $ps->setFitToWidth(0);
        $ps->setFitToHeight(0);
        $ps->setScale(100);
        $ws->setBreak('A25', Worksheet::BREAK_ROW);
    }

    /**
     * @param array{
     *   registeredAt: ?\DateTimeImmutable,
     *   fiberLabel: string,
     *   familyLabel: string,
     *   poznamka: string
     * } $karta
     */
    private function fillHeader(Worksheet $ws, Spool $spool, array $karta): void
    {
        $ws->setCellValue('B3', $spool->getReelNumber());
        $ws->setCellValue('A5', $spool->getTotalLengthM());
        $ws->setCellValue('C5', $spool->getInitialVisibleM());

        if (null !== $karta['registeredAt']) {
            $this->setDateCell($ws, 'D2', $karta['registeredAt']);
        }

        $ws->setCellValue('I2', $karta['fiberLabel']);
        $ws->setCellValue('H3', $karta['familyLabel']);

        if ('' !== $karta['poznamka']) {
            $ws->setCellValue('F5', $karta['poznamka']);
        }
    }

    private function clearDiaryDataAreas(Worksheet $ws): void
    {
        for ($row = self::DIARY_PAGE1_FIRST_ROW; $row <= self::DIARY_PAGE1_LAST_ROW; ++$row) {
            $this->clearDiaryRow($ws, $row);
        }
        for ($row = self::DIARY_PAGE2_FIRST_ROW; $row <= self::DIARY_PAGE2_LAST_ROW; ++$row) {
            $this->clearDiaryRow($ws, $row);
        }
    }

    private function clearDiaryRow(Worksheet $ws, int $row): void
    {
        $ws->setCellValue('A'.$row, null);
        $ws->setCellValue('C'.$row, null);
        $ws->setCellValue('E'.$row, null);
        $ws->setCellValue('H'.$row, null);
    }

    /**
     * @param list<array{occurredAt: \DateTimeImmutable, projectLabel: string, visibleM: ?int, remainingM: int}> $rows
     */
    private function fillDiaryBlock(Worksheet $ws, int $startRow, array $rows): void
    {
        $row = $startRow;
        foreach ($rows as $entry) {
            $this->setDateCell($ws, 'A'.$row, $entry['occurredAt']);
            $ws->setCellValue('C'.$row, $entry['projectLabel']);
            if (null !== $entry['visibleM']) {
                $ws->setCellValue('E'.$row, $entry['visibleM']);
            }
            $ws->setCellValue('H'.$row, $entry['remainingM']);
            ++$row;
        }
    }

    private function setDateCell(Worksheet $ws, string $coordinate, \DateTimeInterface $date): void
    {
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $ws->setCellValue($coordinate, Date::PHPToExcel($day));
        $ws->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
    }

    private function stream(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return new StreamedResponse(
            static function () use ($spreadsheet): void {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            ],
        );
    }

    private function filename(Spool $spool): string
    {
        $reel = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $spool->getReelNumber()) ?? 'civka';

        return 'skladova-karta-'.$reel.'.xlsx';
    }
}
