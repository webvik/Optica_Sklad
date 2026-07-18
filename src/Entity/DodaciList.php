<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DodaciListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DodaciListRepository::class)]
#[ORM\Table(name: 'dodaci_list')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_dodaci_list_doc_date', columns: ['document_date'])]
class DodaciList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Číslo dodacího listu (např. 260103210). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $documentNumber = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $documentDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, DodaciListPage> */
    #[ORM\OneToMany(targetEntity: DodaciListPage::class, mappedBy: 'dodaciList', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $pages;

    public function __construct()
    {
        $this->pages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): static
    {
        $documentNumber = null !== $documentNumber ? \trim($documentNumber) : null;
        $this->documentNumber = (null === $documentNumber || '' === $documentNumber) ? null : $documentNumber;

        return $this;
    }

    public function getDocumentDate(): ?\DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function setDocumentDate(?\DateTimeImmutable $documentDate): static
    {
        $this->documentDate = $documentDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** @return Collection<int, DodaciListPage> */
    public function getPages(): Collection
    {
        return $this->pages;
    }

    public function addPage(DodaciListPage $page): static
    {
        if (!$this->pages->contains($page)) {
            $this->pages->add($page);
            $page->setDodaciList($this);
        }

        return $this;
    }

    public function removePage(DodaciListPage $page): static
    {
        if ($this->pages->removeElement($page) && $page->getDodaciList() === $this) {
            $page->setDodaciList(null);
        }

        return $this;
    }

    public function getPageCount(): int
    {
        return $this->pages->count();
    }

    public function getLabel(): string
    {
        $n = $this->documentNumber;
        if (null !== $n && '' !== $n) {
            return $n;
        }

        return 'DL #'.(string) ($this->id ?? '?');
    }
}
