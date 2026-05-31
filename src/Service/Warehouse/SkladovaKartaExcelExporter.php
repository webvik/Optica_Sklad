<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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

    /**
     * LibreOffice jinak než Excel: obsah se nevejde na 2× A4 při scale 100 %.
     * Pouze pro cestu Excel → PDF (desktop .xlsx zůstává 100 %).
     */
    private const PDF_PRINT_SCALE = 81;

    private const PDF_MARGIN_TOP = 0.51181102362205;

    private const PDF_MARGIN_BOTTOM = 0.393700787401575;

    private const PDF_MARGIN_HEADER = 0.118110236220472;

    private const PDF_MARGIN_FOOTER = 0.118110236220472;

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
        $prepared = $this->prepare($spool);

        return [
            'response' => $this->streamXlsx($prepared['spreadsheet'], $prepared['filename']),
            'truncated' => $prepared['truncated'],
            'diaryRows' => $prepared['diaryRows'],
        ];
    }

    /**
     * @return array{spreadsheet: Spreadsheet, truncated: bool, diaryRows: int, filename: string}
     */
    public function prepare(Spool $spool): array
    {
        return $this->buildPrepared($spool, false);
    }

    /**
     * Stejná data jako {@see prepare()}, ale s úpravou stránkování pro LibreOffice → PDF (2 listy A4).
     *
     * @return array{spreadsheet: Spreadsheet, truncated: bool, diaryRows: int, filename: string}
     */
    public function prepareForPdf(Spool $spool): array
    {
        return $this->buildPrepared($spool, true);
    }

    /**
     * @return array{spreadsheet: Spreadsheet, truncated: bool, diaryRows: int, filename: string}
     */
    private function buildPrepared(Spool $spool, bool $forPdf): array
    {
        $path = $this->projectDir.self::TEMPLATE_REL;
        if (!is_readable($path)) {
            throw new \RuntimeException('Chybí šablona skladové karty: '.$path);
        }

        $karta = $this->dataBuilder->build($spool);

        $spreadsheet = IOFactory::load($path);
        $this->keepSingleSheet($spreadsheet);
        $ws = $spreadsheet->getActiveSheet();
        if ($forPdf) {
            $this->ensurePdfPrintLayout($ws);
        } else {
            $this->ensureDuplexPrintLayout($ws);
        }

        $perPage = SkladovaKartaDataBuilder::MAX_DIARY_ROWS_PER_PAGE;
        $diaryRows = $karta['diaryRows'];
        $page1Rows = \array_slice($diaryRows, 0, $perPage);
        $page2Rows = \array_slice($diaryRows, $perPage, SkladovaKartaDataBuilder::MAX_DIARY_ROWS_PAGE2);

        $this->fillHeader($ws, $spool, $karta);
        $this->clearDiaryDataAreas($ws);
        $this->fillDiaryBlock($ws, self::DIARY_PAGE1_FIRST_ROW, $page1Rows);
        $this->fillDiaryBlock($ws, self::DIARY_PAGE2_FIRST_ROW, $page2Rows);
        $this->ensureDiaryRowsVerticallyCentered($ws);

        return [
            'spreadsheet' => $spreadsheet,
            'truncated' => $karta['truncated'],
            'diaryRows' => \count($diaryRows),
            'filename' => $this->filename($spool),
        ];
    }

    /** LibreOffice PDF: menší okraje + scale 81 % → 2× A4 (Excel tisk zůstává 100 %). */
    private function ensurePdfPrintLayout(Worksheet $ws): void
    {
        $this->ensureDuplexPrintLayout($ws);

        $ps = $ws->getPageSetup();
        $ps->setScale(self::PDF_PRINT_SCALE);

        $margins = $ws->getPageMargins();
        $margins->setTop(self::PDF_MARGIN_TOP);
        $margins->setBottom(self::PDF_MARGIN_BOTTOM);
        $margins->setHeader(self::PDF_MARGIN_HEADER);
        $margins->setFooter(self::PDF_MARGIN_FOOTER);
    }

    public function pdfFilename(Spool $spool): string
    {
        return str_replace('.xlsx', '.pdf', $this->filename($spool));
    }

    /**
     * @param list<Spool> $spools
     *
     * @return array{spreadsheet: Spreadsheet, truncated: bool, filename: string}
     */
    public function prepareBatchForPdf(array $spools): array
    {
        if ($spools === []) {
            throw new \InvalidArgumentException('Vyberte alespoň jednu cívku.');
        }
        if (\count($spools) > 50) {
            throw new \InvalidArgumentException('Maximum 50 karet v jednom souboru.');
        }

        $master = null;
        $truncatedAny = false;
        $usedTitles = [];

        foreach ($spools as $spool) {
            $prepared = $this->buildPrepared($spool, true);
            if ($prepared['truncated']) {
                $truncatedAny = true;
            }

            $source = $prepared['spreadsheet'];
            $ws = $source->getActiveSheet();
            $ws->setTitle($this->uniqueSheetTitle($spool, $usedTitles));

            if (null === $master) {
                $master = $source;
            } else {
                $master->addExternalSheet($ws);
                $source->disconnectWorksheets();
            }
        }

        if (null === $master) {
            throw new \RuntimeException('Nepodařilo se sestavit dávkový export skladových karet.');
        }

        return [
            'spreadsheet' => $master,
            'truncated' => $truncatedAny,
            'filename' => $this->batchFilename($spools),
        ];
    }

    /** @param list<Spool> $spools */
    public function batchPdfFilename(array $spools): string
    {
        return str_replace('.xlsx', '.pdf', $this->batchFilename($spools));
    }

    /** @param list<Spool> $spools */
    public function batchFilename(array $spools): string
    {
        $date = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (1 === \count($spools)) {
            return $this->filename($spools[0]);
        }

        return 'skladove-karty-'.$date.'-'.\count($spools).'x.xlsx';
    }

    /**
     * @param array<string, true> $usedTitles
     */
    private function uniqueSheetTitle(Spool $spool, array &$usedTitles): string
    {
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $spool->getReelNumber()) ?? 'civka';
        $base = mb_substr($base, 0, 28);
        $title = $base;
        $suffix = 1;
        while (isset($usedTitles[$title])) {
            $suffixStr = '_'.$suffix;
            $title = mb_substr($base, 0, 31 - mb_strlen($suffixStr)).$suffixStr;
            ++$suffix;
        }
        $usedTitles[$title] = true;

        return $title;
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

    /** Sloučené buňky deníku (A:I) — obsah vertikálně na střed řádku. */
    private function ensureDiaryRowsVerticallyCentered(Worksheet $ws): void
    {
        for ($row = self::DIARY_PAGE1_FIRST_ROW; $row <= self::DIARY_PAGE1_LAST_ROW; ++$row) {
            $this->applyDiaryRowVerticalCenter($ws, $row);
        }
        for ($row = self::DIARY_PAGE2_FIRST_ROW; $row <= self::DIARY_PAGE2_LAST_ROW; ++$row) {
            $this->applyDiaryRowVerticalCenter($ws, $row);
        }
    }

    private function applyDiaryRowVerticalCenter(Worksheet $ws, int $row): void
    {
        $ws->getStyle('A'.$row.':I'.$row)
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function setDateCell(Worksheet $ws, string $coordinate, \DateTimeInterface $date): void
    {
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $ws->setCellValue($coordinate, Date::PHPToExcel($day));
        $ws->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
    }

    public function saveXlsxToPath(Spreadsheet $spreadsheet, string $path): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
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
