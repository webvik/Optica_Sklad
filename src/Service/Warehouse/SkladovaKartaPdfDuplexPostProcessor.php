<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use setasign\Fpdi\Fpdi;

/**
 * Duplex okraje v PDF po LibreOffice: liché strany posun vpravo (větší vlevo),
 * sudé vlevo (větší vpravo). Excel export se nemění.
 */
final class SkladovaKartaPdfDuplexPostProcessor
{
    /**
     * Rozdíl okrajů ve šabloně Excel (gutter − outer) ≈ 5 mm.
     * Posun ± polovina → celkem 5 mm mezi lichou a sudou stranou.
     */
    private const GUTTER_DIFF_MM = 5.0;

    public function applyInPlace(string $pdfPath): void
    {
        $tmp = $pdfPath.'.duplex-'.bin2hex(random_bytes(4)).'.pdf';
        try {
            $this->apply($pdfPath, $tmp);
            if (!@rename($tmp, $pdfPath) && !@copy($tmp, $pdfPath)) {
                throw new \RuntimeException('Nelze uložit PDF po úpravě duplex okrajů.');
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function apply(string $sourcePath, string $targetPath): void
    {
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException('PDF pro duplex okraje nelze načíst: '.$sourcePath);
        }

        $shiftPt = (self::GUTTER_DIFF_MM / 2.0) / 25.4 * 72.0;

        $pdf = new Fpdi();
        $pdf->setAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($sourcePath);

        for ($pageNo = 1; $pageNo <= $pageCount; ++$pageNo) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            if (false === $size) {
                throw new \RuntimeException('Nelze načíst stranu '.$pageNo.' PDF skladové karty.');
            }

            $orientation = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
            $pdf->AddPage($orientation, [(float) $size['width'], (float) $size['height']]);

            $xOffset = (1 === $pageNo % 2) ? $shiftPt : -$shiftPt;
            $pdf->useTemplate($templateId, $xOffset, 0.0);
        }

        $pdf->Output('F', $targetPath);

        if (!is_readable($targetPath) || 0 === filesize($targetPath)) {
            throw new \RuntimeException('Postprocessing duplex okrajů PDF nevrátil platný soubor.');
        }
    }
}
