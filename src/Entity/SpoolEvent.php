<?php

namespace App\Entity;

use App\Enum\SpoolEventType;
use App\Repository\SpoolEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpoolEventRepository::class)]
#[ORM\Table(name: 'cable_spool_event')]
#[ORM\Index(name: 'idx_event_spool_time', columns: ['spool_id', 'occurred_at'])]
class SpoolEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Spool $spool = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $occurredAt = null;

    #[ORM\Column(length: 32, enumType: SpoolEventType::class)]
    private SpoolEventType $type = SpoolEventType::MeterReading;

    #[ORM\Column(nullable: true)]
    private ?int $visibleM = null;

    #[ORM\Column(nullable: true)]
    private ?int $usedMeters = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $projectLabel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $correctsEvent = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->occurredAt = new \DateTimeImmutable();
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

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getType(): SpoolEventType
    {
        return $this->type;
    }

    public function setType(SpoolEventType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getVisibleM(): ?int
    {
        return $this->visibleM;
    }

    public function setVisibleM(?int $visibleM): static
    {
        $this->visibleM = $visibleM;

        return $this;
    }

    public function getUsedMeters(): ?int
    {
        return $this->usedMeters;
    }

    public function setUsedMeters(?int $usedMeters): static
    {
        $this->usedMeters = $usedMeters;

        return $this;
    }

    public function getProjectLabel(): ?string
    {
        return $this->projectLabel;
    }

    public function setProjectLabel(?string $projectLabel): static
    {
        $this->projectLabel = $projectLabel;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCorrectsEvent(): ?self
    {
        return $this->correctsEvent;
    }

    public function setCorrectsEvent(?self $correctsEvent): static
    {
        $this->correctsEvent = $correctsEvent;

        return $this;
    }
}
