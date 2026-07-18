<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\DodaciList;
use App\Entity\DodaciListPage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Úložiště skenů dodacích listů pod var/dodaci_list/{id}/.
 */
final class DodaciListStorage
{
    private readonly string $rootDir;

    public function __construct(string $projectDir)
    {
        $this->rootDir = \rtrim($projectDir, '/\\').\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'dodaci_list';
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function absolutePath(DodaciListPage $page): string
    {
        $rel = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $page->getStoragePath());

        return $this->rootDir.\DIRECTORY_SEPARATOR.$rel;
    }

    /**
     * @param list<UploadedFile> $files v pořadí stránek
     *
     * @return list<DodaciListPage>
     */
    public function storePages(DodaciList $list, array $files): array
    {
        $id = $list->getId();
        if (null === $id) {
            throw new \LogicException('DodaciList musí být nejdřív flushnutý (má id).');
        }
        $dir = $this->rootDir.\DIRECTORY_SEPARATOR.(string) $id;
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Nelze vytvořit adresář archivu dodacích listů.');
        }

        $pages = [];
        $pos = 1;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new \InvalidArgumentException('Neplatný soubor stránky '.$pos.'.');
            }
            // Bez symfony/mime: getMimeType()/guessExtension() hodí výjimku.
            $origName = (string) ($file->getClientOriginalName() ?: '');
            $mime = \strtolower((string) ($file->getClientMimeType() ?: ''));
            $ext = \strtolower((string) ($file->getClientOriginalExtension() ?: ''));
            if ('' === $ext && '' !== $origName && \preg_match('/\.([a-z0-9]{2,5})$/i', $origName, $em)) {
                $ext = \strtolower($em[1]);
            }
            if ('jpeg' === $ext) {
                $ext = 'jpg';
            }
            $allowedExt = ['jpg', 'png', 'webp', 'gif'];
            $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/pjpeg'];
            $okMime = '' !== $mime && (\str_starts_with($mime, 'image/') || \in_array($mime, $allowedMime, true));
            $okExt = \in_array($ext, $allowedExt, true);
            if (!$okMime && !$okExt) {
                throw new \InvalidArgumentException('Stránka '.$pos.': očekáván obrázek (JPG/PNG…).');
            }
            if (!$okExt) {
                $ext = match (true) {
                    \str_contains($mime, 'png') => 'png',
                    \str_contains($mime, 'webp') => 'webp',
                    \str_contains($mime, 'gif') => 'gif',
                    default => 'jpg',
                };
            }
            if ('' === $mime || !\str_starts_with($mime, 'image/')) {
                $mime = match ($ext) {
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                    default => 'image/jpeg',
                };
            }
            $safeName = \sprintf('%02d_%s.%s', $pos, \bin2hex(\random_bytes(6)), $ext);
            $file->move($dir, $safeName);

            $page = new DodaciListPage();
            $page->setPosition($pos);
            $page->setOriginalFilename('' !== $origName ? $origName : $safeName);
            $page->setStoragePath($id.'/'.$safeName);
            $page->setMimeType($mime);
            $page->setSizeBytes((int) \filesize($dir.\DIRECTORY_SEPARATOR.$safeName));
            $list->addPage($page);
            $pages[] = $page;
            ++$pos;
        }

        return $pages;
    }

    public function deleteAllFiles(DodaciList $list): void
    {
        $id = $list->getId();
        if (null === $id) {
            return;
        }
        $dir = $this->rootDir.\DIRECTORY_SEPARATOR.(string) $id;
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$name;
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
