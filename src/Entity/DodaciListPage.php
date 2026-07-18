<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DodaciListPageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DodaciListPageRepository::class)]
#[ORM\Table(name: 'dodaci_list_page')]
#[ORM\UniqueConstraint(name: 'uniq_dodaci_list_page_pos', columns: ['dodaci_list_id', 'position'])]
class DodaciListPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DodaciList $dodaciList = null;

    /** Pořadí stránky při nahrání (1…N). */
    #[ORM\Column]
    private int $position = 1;

    #[ORM\Column(length: 255)]
    private string $originalFilename = '';

    /** Relativní cesta pod kořenem úložiště, např. \"12/01_abcd.jpg\". */
    #[ORM\Column(length: 512)]
    private string $storagePath = '';

    #[ORM\Column(length: 128)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column]
    private int $sizeBytes = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDodaciList(): ?DodaciList
    {
        return $this->dodaciList;
    }

    public function setDodaciList(?DodaciList $dodaciList): static
    {
        $this->dodaciList = $dodaciList;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

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
}
