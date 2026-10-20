<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use App\Entity\SpoolPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Úložiště fotek cívek pod var/spool_photo/{spoolId}/ — max. 3, nejstarší se maže.
 */
final class SpoolPhotoStorage
{
    public const MAX_PHOTOS = 3;

    private const MAX_BYTES = 12_582_912; // 12 MiB

    private readonly string $rootDir;

    public function __construct(
        string $projectDir,
        private readonly EntityManagerInterface $em,
    ) {
        $this->rootDir = \rtrim($projectDir, '/\\').\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'spool_photo';
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function absolutePath(SpoolPhoto $photo): string
    {
        $rel = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $photo->getStoragePath());

        return $this->rootDir.\DIRECTORY_SEPARATOR.$rel;
    }

    public function store(Spool $spool, UploadedFile $file): SpoolPhoto
    {
        $id = $spool->getId();
        if (null === $id) {
            throw new \LogicException('Spool musí mít id před uložením fotky.');
        }
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Neplatný soubor fotky.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Fotka je příliš velká (max. 12 MB).');
        }

        [$ext, $mime] = $this->resolveExtAndMime($file);

        $dir = $this->rootDir.\DIRECTORY_SEPARATOR.(string) $id;
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Nelze vytvořit adresář pro fotku cívky.');
        }

        $safeName = \bin2hex(\random_bytes(8)).'.'.$ext;
        $file->move($dir, $safeName);
        $abs = $dir.\DIRECTORY_SEPARATOR.$safeName;

        $photo = new SpoolPhoto();
        $photo->setStoragePath($id.'/'.$safeName);
        $photo->setMimeType($mime);
        $photo->setSizeBytes((int) \filesize($abs));
        $spool->addPhoto($photo);
        $this->em->persist($photo);

        $this->enforceMaxPhotos($spool);

        return $photo;
    }

    public function deleteOne(SpoolPhoto $photo): void
    {
        $spool = $photo->getSpool();
        $path = $this->absolutePath($photo);
        if (\is_file($path)) {
            @\unlink($path);
        }
        if (null !== $spool) {
            $spool->removePhoto($photo);
        }
        $this->em->remove($photo);
        $this->cleanupEmptyDir($spool);
    }

    public function deleteAllForSpool(Spool $spool): void
    {
        foreach ($spool->getPhotos()->toArray() as $photo) {
            $path = $this->absolutePath($photo);
            if (\is_file($path)) {
                @\unlink($path);
            }
            $spool->removePhoto($photo);
            $this->em->remove($photo);
        }
        $this->cleanupEmptyDir($spool);
    }

    /** Po přidání: pokud > MAX, smaž nejstarší (created_at ASC, pak id). */
    private function enforceMaxPhotos(Spool $spool): void
    {
        $all = $spool->getPhotos()->toArray();
        if (\count($all) <= self::MAX_PHOTOS) {
            return;
        }
        \usort(
            $all,
            static function (SpoolPhoto $a, SpoolPhoto $b): int {
                $c = $a->getCreatedAt() <=> $b->getCreatedAt();
                if (0 !== $c) {
                    return $c;
                }

                return ($a->getId() ?? 0) <=> ($b->getId() ?? 0);
            },
        );
        $toRemove = \array_slice($all, 0, \count($all) - self::MAX_PHOTOS);
        foreach ($toRemove as $old) {
            $this->deleteOne($old);
        }
    }

    private function cleanupEmptyDir(?Spool $spool): void
    {
        if (null === $spool || null === $spool->getId()) {
            return;
        }
        $dir = $this->rootDir.\DIRECTORY_SEPARATOR.(string) $spool->getId();
        if (!\is_dir($dir)) {
            return;
        }
        $left = \array_diff(\scandir($dir) ?: [], ['.', '..']);
        if ([] === $left) {
            @\rmdir($dir);
        }
    }

    /**
     * @return array{0: string, 1: string} ext, mime
     */
    private function resolveExtAndMime(UploadedFile $file): array
    {
        $origName = (string) ($file->getClientOriginalName() ?: '');
        $mime = \strtolower((string) ($file->getClientMimeType() ?: ''));
        $ext = \strtolower((string) ($file->getClientOriginalExtension() ?: ''));
        if ('' === $ext && '' !== $origName && \preg_match('/\.([a-z0-9]{2,5})$/i', $origName, $em)) {
            $ext = \strtolower($em[1]);
        }
        if ('jpeg' === $ext) {
            $ext = 'jpg';
        }
        $allowedExt = ['jpg', 'png', 'webp', 'gif', 'heic', 'heif'];
        $allowedMime = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif',
            'image/pjpeg', 'image/heic', 'image/heif',
        ];
        $okMime = '' !== $mime && (\str_starts_with($mime, 'image/') || \in_array($mime, $allowedMime, true));
        $okExt = \in_array($ext, $allowedExt, true);
        if (!$okMime && !$okExt) {
            throw new \InvalidArgumentException('Očekáván obrázek (JPG/PNG/WebP…).');
        }
        if (!$okExt) {
            $ext = match (true) {
                \str_contains($mime, 'png') => 'png',
                \str_contains($mime, 'webp') => 'webp',
                \str_contains($mime, 'gif') => 'gif',
                \str_contains($mime, 'heic'), \str_contains($mime, 'heif') => 'heic',
                default => 'jpg',
            };
        }
        if ('' === $mime || !\str_starts_with($mime, 'image/')) {
            $mime = match ($ext) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'heic', 'heif' => 'image/heic',
                default => 'image/jpeg',
            };
        }

        return [$ext, $mime];
    }
}
