<?php

namespace App\Entity;

use App\Repository\CableTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CableTypeRepository::class)]
#[ORM\Table(name: 'cable_type')]
#[ORM\HasLifecycleCallbacks]
class CableType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fullDescription = null;

    /** blown / mlt / drop / fletka / … */
    #[ORM\Column(length: 32)]
    private string $family = '';

    #[ORM\Column]
    private int $fiberCount = 0;

    /** Напр. Z444 */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $constructionCode = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $diameterMm = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /** @var Collection<int, Spool> */
    #[ORM\OneToMany(targetEntity: Spool::class, mappedBy: 'cableType', orphanRemoval: true)]
    private Collection $spools;

    public function __construct()
    {
        $this->spools = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getFullDescription(): ?string
    {
        return $this->fullDescription;
    }

    public function setFullDescription(?string $fullDescription): static
    {
        $this->fullDescription = $fullDescription;

        return $this;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function setFamily(string $family): static
    {
        $this->family = $family;

        return $this;
    }

    public function getFiberCount(): int
    {
        return $this->fiberCount;
    }

    public function setFiberCount(int $fiberCount): static
    {
        $this->fiberCount = $fiberCount;

        return $this;
    }

    public function getConstructionCode(): ?string
    {
        return $this->constructionCode;
    }

    public function setConstructionCode(?string $constructionCode): static
    {
        $this->constructionCode = $constructionCode;

        return $this;
    }

    public function getDiameterMm(): ?string
    {
        return $this->diameterMm;
    }

    public function setDiameterMm(?string $diameterMm): static
    {
        $this->diameterMm = $diameterMm;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * @return Collection<int, Spool>
     */
    public function getSpools(): Collection
    {
        return $this->spools;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
