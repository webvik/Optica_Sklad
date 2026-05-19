<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export inventurních tabulek (krátká / plná) do .xlsx.
 */
final class InventuraExcelExporter
{
    /**
     * @param list<array{
     *   groupLabel: string,
     *   rows: list<Spool>,
     *   sumM: int,
     *   sumR: int,
     *   spoolCount: int,
     *   minM: int|null,
     *   maxM: int|null
     * }> $groups
     */
    public function downloadBrief(array $groups, \DateTimeImmutable $generatedAt): Response
    {
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Krátká inventura');

        $headers = [
            'Skupina (vl. · family)',
            'Stav (m) — součet',
            'Rezervováno (m)',
            'Cívek',
            'Max. v kuse (m)',
            'Min. v kuse (m)',
        ];
        $ws->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($ws, 1, \count($headers));

        $row = 2;
        foreach ($groups as $g) {
            $ws->fromArray([
                $g['groupLabel'],
                $g['sumM'],
                $g['sumR'],
                $g['spoolCount'],
                $g['maxM'],
                $g['minM'],
            ], null, 'A'.$row);
            ++$row;
        }

        $this->autosizeColumns($ws, \count($headers));

        return $this->stream($sheet, $this->filename('inventura-krata', $generatedAt));
    }

    /**
     * @param list<array{
     *   groupLabel: string,
     *   rows: list<Spool>,
     *   sumM: int,
     *   sumR: int,
     *   spoolCount: int,
     *   minM: int|null,
     *   maxM: int|null
     * }> $groups
     */
    public function downloadFull(array $groups, \DateTimeImmutable $generatedAt): Response
    {
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Plná inventura');

        $headers = [
            'Evidenční číslo',
            'Kód zásoby',
            'Název',
            'Stav (m)',
            'Rezervováno (m)',
        ];
        $ws->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($ws, 1, \count($headers));

        $row = 2;
        foreach ($groups as $g) {
            $ws->setCellValue('A'.$row, 'Skupina: '.$g['groupLabel']);
            $ws->mergeCells('A'.$row.':E'.$row);
            $this->styleGroupHeaderRow($ws, $row);
            ++$row;

            foreach ($g['rows'] as $s) {
                $ws->fromArray([
                    $s->getReelNumber(),
                    $s->getCableType() ? $s->getCableType()->getCode() : '—',
                    $this->spoolDisplayName($s),
                    $s->getCurrentRemainingM(),
                    ($s->getReservedM() ?? 0) > 0 ? $s->getReservedM() : 0,
                ], null, 'A'.$row);
                ++$row;
            }

            $ws->fromArray([
                '',
                '',
                'Součet skupiny',
                $g['sumM'],
                $g['sumR'],
            ], null, 'A'.$row);
            $this->styleGroupSumRow($ws, $row);
            ++$row;
        }

        $this->autosizeColumns($ws, \count($headers));

        return $this->stream($sheet, $this->filename('inventura-plna', $generatedAt));
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

    private function filename(string $prefix, \DateTimeImmutable $at): string
    {
        return $prefix.'-'.$at->format('Y-m-d_His').'.xlsx';
    }

    private function spoolDisplayName(Spool $s): string
    {
        $ct = $s->getCableType();
        if (null === $ct) {
            return '—';
        }
        $raw = trim((string) ($ct->getFullDescription() ?? ''));
        if ('' !== $raw) {
            return str_replace(["\n", "\r"], ' ', $raw);
        }

        return (string) $ct->getName();
    }

    private function styleHeaderRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $row, int $colCount): void
    {
        $range = 'A'.$row.':'.$this->columnLetter($colCount).$row;
        $ws->getStyle($range)->getFont()->setBold(true);
        $ws->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');
    }

    private function styleGroupHeaderRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $row): void
    {
        $range = 'A'.$row.':E'.$row;
        $ws->getStyle($range)->getFont()->setBold(true);
        $ws->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE8EEF5');
    }

    private function styleGroupSumRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $row): void
    {
        $range = 'A'.$row.':E'.$row;
        $ws->getStyle($range)->getFont()->setBold(true);
        $ws->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF7F7F7');
    }

    private function autosizeColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $colCount): void
    {
        for ($c = 1; $c <= $colCount; ++$c) {
            $letter = $this->columnLetter($c);
            $ws->getColumnDimension($letter)->setAutoSize(true);
        }
    }

    private function columnLetter(int $col): string
    {
        return Coordinate::stringFromColumnIndex($col);
    }
}
