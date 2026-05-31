<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

/**
 * Skladová karta: Excel ze šablony → PDF přes LibreOffice (stejný tiskový layout).
 */
final class SkladovaKartaPdfExporter
{
    /** @var list<string> */
    private const LO_BINARIES = ['libreoffice', 'soffice', '/usr/bin/libreoffice', '/usr/bin/soffice'];

    public function __construct(
        private readonly SkladovaKartaExcelExporter $excelExporter,
        #[Autowire('%env(SKLADOVA_KARTA_LO_BIN)%')]
        private readonly string $loBinOverride,
    ) {
    }

    /**
     * @return array{response: Response, truncated: bool, diaryRows: int}
     */
    public function download(Spool $spool): array
    {
        $prepared = $this->excelExporter->prepareForPdf($spool);
        $pdfFilename = $this->excelExporter->pdfFilename($spool);

        $tmpdir = sys_get_temp_dir().'/sk-karta-'.bin2hex(random_bytes(8));
        if (!mkdir($tmpdir, 0700, true) && !is_dir($tmpdir)) {
            throw new \RuntimeException('Nelze vytvořit dočasný adresář pro PDF.');
        }

        $xlsxPath = $tmpdir.'/karta.xlsx';
        $pdfPath = $tmpdir.'/karta.pdf';

        try {
            $this->excelExporter->saveXlsxToPath($prepared['spreadsheet'], $xlsxPath);
            $this->convertXlsxToPdf($xlsxPath, $tmpdir);

            if (!is_readable($pdfPath)) {
                throw new \RuntimeException('Konverze Excel → PDF nevrátila soubor.');
            }

            return [
                'response' => $this->streamPdf($pdfPath, $pdfFilename, $tmpdir),
                'truncated' => $prepared['truncated'],
                'diaryRows' => $prepared['diaryRows'],
            ];
        } catch (\Throwable $e) {
            $this->removeDir($tmpdir);

            throw $e;
        }
    }

    private function convertXlsxToPdf(string $xlsxPath, string $outDir): void
    {
        $binary = $this->resolveLibreOfficeBinary();
        $profileDir = $outDir.'/lo-profile';
        if (!mkdir($profileDir, 0700, true) && !is_dir($profileDir)) {
            throw new \RuntimeException('Nelze vytvořit profil LibreOffice.');
        }

        $process = new Process([
            $binary,
            '-env:UserInstallation=file://'.$profileDir,
            '--headless',
            '--norestore',
            '--nologo',
            '--convert-to',
            'pdf',
            '--outdir',
            $outDir,
            $xlsxPath,
        ]);
        $process->setTimeout(120);
        $process->setEnv([
            'HOME' => $outDir,
            'LANG' => 'cs_CZ.UTF-8',
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'Konverze Excel → PDF selhala (LibreOffice). Na serveru nainstalujte balíček libreoffice-calc. '
                .trim($process->getErrorOutput().' '.$process->getOutput()),
            );
        }
    }

    private function resolveLibreOfficeBinary(): string
    {
        $override = trim($this->loBinOverride);
        if ('' !== $override) {
            if (!is_executable($override)) {
                throw new \RuntimeException('SKLADOVA_KARTA_LO_BIN neukazuje na spustitelný LibreOffice: '.$override);
            }

            return $override;
        }

        foreach (self::LO_BINARIES as $candidate) {
            if ('/' === $candidate[0]) {
                if (is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }
            $which = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
            if ('' !== $which && is_executable($which)) {
                return $which;
            }
        }

        throw new \RuntimeException(
            'LibreOffice není nainstalován — PDF skladové karty nelze vytvořit. Nainstalujte: sudo apt install libreoffice-calc',
        );
    }

    private function streamPdf(string $pdfPath, string $filename, string $tmpdir): StreamedResponse
    {
        return new StreamedResponse(
            static function () use ($pdfPath, $tmpdir): void {
                try {
                    $h = fopen($pdfPath, 'rb');
                    if (false === $h) {
                        return;
                    }
                    fpassthru($h);
                    fclose($h);
                } finally {
                    SkladovaKartaPdfExporter::removeDirStatic($tmpdir);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            ],
        );
    }

    private function removeDir(string $dir): void
    {
        self::removeDirStatic($dir);
    }

    private static function removeDirStatic(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                self::removeDirStatic($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
