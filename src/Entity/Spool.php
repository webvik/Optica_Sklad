<?php

namespace App\Entity;

use App\Enum\SpoolStatus;
use App\Repository\SpoolRepository;
use App\Service\Warehouse\InventuraBriefGroupLabel;
use App\Service\Warehouse\SpoolEventOrder;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: SpoolRepository::class)]
#[ORM\Table(name: 'cable_spool')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_spool_reel', columns: ['reel_number'])]
#[UniqueEntity(fields: ['reelNumber'], message: 'Toto číslo saře má již jiná cívka. Druhou cívku se stejným číslem evidovat nelze.', errorPath: 'reelNumber')]
class Spool
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'spools')]
    #[ORM\JoinColumn(nullable: true)]
    private ?CableType $cableType = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $reelNumber = '';

    /** Denormalizace z cable_type.family (rychlé filtry / reporty) */
    #[ORM\Column(length: 32)]
    private string $family = '';

    #[ORM\Column]
    private int $totalLengthM = 0;

    /** m0 — метр на видимом конце при приёмке */
    #[ORM\Column]
    private int $initialVisibleM = 0;

    #[ORM\Column(nullable: true)]
    private ?int $currentRemainingM = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastVisibleM = null;

    /** +1 / −1 после первой отмотки, иначе null */
    #[ORM\Column(nullable: true, options: ['default' => null])]
    private ?int $meterSign = null;

    /** Переопределение от типа; null — брать с CableType */
    #[ORM\Column(nullable: true)]
    private ?int $fiberCount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $diameterMm = null;

    #[ORM\Column(length: 20, enumType: SpoolStatus::class)]
    private SpoolStatus $status = SpoolStatus::InStock;

    /** Ruční příznak: cívka čeká na opravu dat (filtr v Přehledu skladu). */
    #[ORM\Column(options: ['default' => false])]
    private bool $needsCorrection = false;

    /** Co je špatně / co opravit (jen při needs_correction). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctionNote = null;

    #[ORM\Column(nullable: true)]
    private ?int $reservedM = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $registeredAt = null;

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

    /** @var Collection<int, SpoolEvent> */
    #[ORM\OneToMany(targetEntity: SpoolEvent::class, mappedBy: 'spool', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $events;

    public function __construct()
    {
        $this->events = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCableType(): ?CableType
    {
        return $this->cableType;
    }

    public function setCableType(?CableType $cableType): static
    {
        $this->cableType = $cableType;
        if (null === $cableType) {
            $this->family = '';
        } else {
            $this->family = $cableType->getFamily();
        }

        return $this;
    }

    public function getReelNumber(): string
    {
        return $this->reelNumber;
    }

    public function setReelNumber(string $reelNumber): static
    {
        $this->reelNumber = $reelNumber;

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

    public function getTotalLengthM(): int
    {
        return $this->totalLengthM;
    }

    public function setTotalLengthM(int $totalLengthM): static
    {
        $this->totalLengthM = $totalLengthM;

        return $this;
    }

    public function getInitialVisibleM(): int
    {
        return $this->initialVisibleM;
    }

    public function setInitialVisibleM(int $initialVisibleM): static
    {
        $this->initialVisibleM = $initialVisibleM;

        return $this;
    }

    public function getCurrentRemainingM(): ?int
    {
        return $this->currentRemainingM;
    }

    public function setCurrentRemainingM(?int $currentRemainingM): static
    {
        $this->currentRemainingM = $currentRemainingM;

        return $this;
    }

    public function getLastVisibleM(): ?int
    {
        return $this->lastVisibleM;
    }

    public function setLastVisibleM(?int $lastVisibleM): static
    {
        $this->lastVisibleM = $lastVisibleM;

        return $this;
    }

    public function getMeterSign(): ?int
    {
        return $this->meterSign;
    }

    public function setMeterSign(?int $meterSign): static
    {
        $this->meterSign = $meterSign;

        return $this;
    }

    public function getFiberCount(): ?int
    {
        return $this->fiberCount;
    }

    public function setFiberCount(?int $fiberCount): static
    {
        $this->fiberCount = $fiberCount;

        return $this;
    }

    public function getEffectiveFiberCount(): int
    {
        if (null !== $this->fiberCount) {
            return $this->fiberCount;
        }

        return $this->cableType?->getFiberCount() ?? 0;
    }

    public function getDiameterMm(): ?string
    {
        return $this->diameterMm;
    }

    /**
     * Průměr z cívky nebo z typu kabelu (stejná logika jako u vláken).
     */
    public function getEffectiveDiameterMm(): ?string
    {
        if (null !== $this->diameterMm && '' !== \trim((string) $this->diameterMm)) {
            return $this->diameterMm;
        }
        $d = $this->cableType?->getDiameterMm();

        return null !== $d && '' !== \trim((string) $d) ? $d : null;
    }

    public function setDiameterMm(?string $diameterMm): static
    {
        $this->diameterMm = $diameterMm;

        return $this;
    }

    /** Stejný text jako skupina ve zkrácené inventuře (vl. · family · Ø …). */
    public function getBriefInventuraLabel(): string
    {
        $fiber = $this->getEffectiveFiberCount();
        $family = '' !== $this->getFamily() ? $this->getFamily() : '—';
        $diamKey = InventuraBriefGroupLabel::normalizeDiameterKey($this->getEffectiveDiameterMm());

        return InventuraBriefGroupLabel::format($fiber, $family, $diamKey);
    }

    public function getStatus(): SpoolStatus
    {
        return $this->status;
    }

    public function setStatus(SpoolStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isNeedsCorrection(): bool
    {
        return $this->needsCorrection;
    }

    public function setNeedsCorrection(bool $needsCorrection): static
    {
        $this->needsCorrection = $needsCorrection;

        return $this;
    }

    public function getCorrectionNote(): ?string
    {
        return $this->correctionNote;
    }

    public function setCorrectionNote(?string $correctionNote): static
    {
        $this->correctionNote = $correctionNote;

        return $this;
    }

    public function getReservedM(): ?int
    {
        return $this->reservedM;
    }

    public function setReservedM(?int $reservedM): static
    {
        $this->reservedM = $reservedM;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): static
    {
        $this->registeredAt = $registeredAt;

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
     * @return Collection<int, SpoolEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * Deník a řetězec m: dle metráže a směru metru, ne dle data.
     *
     * @return list<SpoolEvent>
     */
    public function getEventsSortedByVisibleM(): array
    {
        return SpoolEventOrder::forSpool($this);
    }

    public function addEvent(SpoolEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setSpool($this);
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
