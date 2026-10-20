<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SpoolPhotoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpoolPhotoRepository::class)]
#[ORM\Table(name: 'cable_spool_photo')]
#[ORM\Index(name: 'idx_spool_photo_spool_created', columns: ['spool_id', 'created_at'])]
class SpoolPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Spool $spool = null;

    /** Relativní cesta pod var/spool_photo/, např. \"12/a1b2.jpg\". */
    #[ORM\Column(length: 512)]
    private string $storagePath = '';

    #[ORM\Column(length: 128)]
    private string $mimeType = 'image/jpeg';

    #[ORM\Column]
    private int $sizeBytes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSpool(): ?Spool
    {
        return $this->spool;
    }

    public function setSpool(?Spool $spool): static
    {
        $this->spool = $spool;

        return $this;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): static
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
