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
 * Dva listy = přední a zadní strana duplexu (deník až 80 řádků).
 */
final class SkladovaKartaExcelExporter
{
    private const TEMPLATE_REL = '/deploy/excel/skladova-karta.template.xlsx';

    private const DIARY_FIRST_ROW = 9;

    private const DIARY_LAST_ROW = 48;

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
        $this->trimExtraSheets($spreadsheet);

        $perPage = SkladovaKartaDataBuilder::MAX_DIARY_ROWS_PER_PAGE;
        $diaryRows = $karta['diaryRows'];
        $sheet1Rows = \array_slice($diaryRows, 0, $perPage);
        $sheet2Rows = \array_slice($diaryRows, $perPage, $perPage);

        $this->fillSheet($spreadsheet->getSheet(0), $spool, $karta, $sheet1Rows);
        $this->fillSheet($spreadsheet->getSheet(1), $spool, $karta, $sheet2Rows);

        $filename = $this->filename($spool);

        return [
            'response' => $this->stream($spreadsheet, $filename),
            'truncated' => $karta['truncated'],
            'diaryRows' => \count($diaryRows),
        ];
    }

    /**
     * Šablona má dva listy se stejným layoutem; odstranit jen případné prázdné listy navíc.
     */
    private function trimExtraSheets(Spreadsheet $spreadsheet): void
    {
        while ($spreadsheet->getSheetCount() > 2) {
            $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
        }

        if ($spreadsheet->getSheetCount() < 2) {
            throw new \RuntimeException('Šablona skladové karty musí mít dva listy (Strana 1 a Strana 2).');
        }
    }

    /**
     * @param array{
     *   registeredAt: ?\DateTimeImmutable,
     *   fiberLabel: string,
     *   familyLabel: string,
     *   note: string
     * } $karta
     * @param list<array{occurredAt: \DateTimeImmutable, projectLabel: string, visibleM: ?int, remainingM: int}> $diaryRows
     */
    private function fillSheet(Worksheet $ws, Spool $spool, array $karta, array $diaryRows): void
    {
        $this->fillHeader($ws, $spool, $karta);
        $this->clearDiaryDataArea($ws);
        $this->fillDiary($ws, $diaryRows);
    }

    /**
     * @param array{
     *   registeredAt: ?\DateTimeImmutable,
     *   fiberLabel: string,
     *   familyLabel: string,
     *   note: string
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

        if ('' !== $karta['note']) {
            $ws->setCellValue('G5', $karta['note']);
        }
    }

    private function clearDiaryDataArea(Worksheet $ws): void
    {
        for ($row = self::DIARY_FIRST_ROW; $row <= self::DIARY_LAST_ROW; ++$row) {
            $ws->setCellValue('A'.$row, null);
            $ws->setCellValue('C'.$row, null);
            $ws->setCellValue('E'.$row, null);
            $ws->setCellValue('H'.$row, null);
        }
    }

    /**
     * @param list<array{occurredAt: \DateTimeImmutable, projectLabel: string, visibleM: ?int, remainingM: int}> $rows
     */
    private function fillDiary(Worksheet $ws, array $rows): void
    {
        $row = self::DIARY_FIRST_ROW;
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
