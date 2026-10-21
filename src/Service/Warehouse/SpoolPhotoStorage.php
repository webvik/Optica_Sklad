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
            throw new \InvalidArgumentException(self::describeUploadError($file));
        }
        $size = $file->getSize();
        if (false === $size || $size < 1) {
            throw new \InvalidArgumentException('Soubor fotky je prázdný.');
        }
        if ($size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Fotka je příliš velká (max. 12 MB). Zkuste fotku zmenšit.');
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

    private static function describeUploadError(UploadedFile $file): string
    {
        $code = $file->getError();
        $iniUpload = (string) \ini_get('upload_max_filesize');
        $iniPost = (string) \ini_get('post_max_size');

        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'Fotka je příliš velká pro server (limit upload '.$iniUpload.', POST '.$iniPost.'). Pořiďte menší snímek nebo počkejte na kompresi v prohlížeči.',
            \UPLOAD_ERR_PARTIAL => 'Nahrání fotky se přerušilo — zkuste znovu (lepší Wi‑Fi / mobilní data).',
            \UPLOAD_ERR_NO_FILE => 'Soubor fotky nedorazil — zkuste znovu Fotoaparát nebo Galerii.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Na serveru chybí dočasný adresář pro upload.',
            \UPLOAD_ERR_CANT_WRITE => 'Server nemohl fotku zapsat na disk (oprávnění).',
            \UPLOAD_ERR_EXTENSION => 'Nahrání fotky zablokovalo rozšíření PHP.',
            default => 'Neplatný soubor fotky (kód '.$code.').',
        };
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
            'image/pjpeg', 'image/heic', 'image/heif', 'application/octet-stream',
        ];
        $okMime = '' !== $mime && (\str_starts_with($mime, 'image/') || \in_array($mime, $allowedMime, true));
        $okExt = \in_array($ext, $allowedExt, true);

        // Mobilní WebView často pošle prázdný / octet-stream MIME — detekce z obsahu.
        if (!$okMime && !$okExt) {
            $tmp = $file->getPathname();
            if ('' !== $tmp && \is_file($tmp)) {
                $info = @\getimagesize($tmp);
                if (\is_array($info) && isset($info[2])) {
                    $ext = match ((int) $info[2]) {
                        \IMAGETYPE_PNG => 'png',
                        \IMAGETYPE_WEBP => 'webp',
                        \IMAGETYPE_GIF => 'gif',
                        default => 'jpg',
                    };
                    $mime = match ($ext) {
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                        'gif' => 'image/gif',
                        default => 'image/jpeg',
                    };
                    $okExt = true;
                    $okMime = true;
                }
            }
        }

        if (!$okMime && !$okExt) {
            throw new \InvalidArgumentException('Očekáván obrázek (JPG/PNG/WebP…). Na iPhonu zvolte „Nejkompatibilnější“ / JPEG, ne HEIC.');
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
