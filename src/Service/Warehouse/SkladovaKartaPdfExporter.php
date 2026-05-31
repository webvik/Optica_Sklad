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
    private const LO_BINARIES = ['/usr/bin/libreoffice', '/usr/bin/soffice', 'libreoffice', 'soffice'];

    public function __construct(
        private readonly SkladovaKartaExcelExporter $excelExporter,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
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

        $tmpdir = $this->cacheDir.'/sk-karta-'.bin2hex(random_bytes(8));
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

        $env = $this->processEnvironment($outDir);

        $process = new Process(
            [
                $binary,
                '-env:UserInstallation='.$this->loUserInstallationUri($profileDir),
                '--headless',
                '--norestore',
                '--nologo',
                '--convert-to',
                'pdf',
                '--outdir',
                $outDir,
                $xlsxPath,
            ],
            $outDir,
            $env,
        );
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput().' '.$process->getOutput());
            throw new \RuntimeException(
                'Konverze Excel → PDF selhala (LibreOffice). '
                .($detail !== '' ? $detail : 'Zkontrolujte, zda uživatel web serveru může spustit libreoffice --headless.'),
            );
        }

        $base = pathinfo($xlsxPath, PATHINFO_FILENAME);
        $generated = $outDir.'/'.$base.'.pdf';
        if (!is_readable($generated)) {
            throw new \RuntimeException('Konverze Excel → PDF nevrátila soubor: '.$generated);
        }
    }

    private function loUserInstallationUri(string $absoluteDir): string
    {
        $path = str_replace('\\', '/', $absoluteDir);

        return 'file://'.(str_starts_with($path, '/') ? '' : '/').$path;
    }

    /**
     * Dědičnost celého prostředí — setEnv() by jinak odřízl LD_LIBRARY_PATH atd.
     *
     * @return array<string, string>
     */
    private function processEnvironment(string $workDir): array
    {
        $env = [];
        foreach ([$_ENV, $_SERVER] as $source) {
            foreach ($source as $key => $value) {
                if (!\is_string($key) || !\is_string($value) || '' === $value) {
                    continue;
                }
                $env[$key] = $value;
            }
        }
        $env['HOME'] = $workDir;
        $env['TMPDIR'] = $workDir;
        $env['LANG'] = 'C.UTF-8';
        if (!isset($env['PATH']) || '' === $env['PATH']) {
            $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        }

        return $env;
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
