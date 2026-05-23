<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectReportAliasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alias zápisu zakázky → kanonický název jen pro reporty (deník na cívce beze změny).
 */
#[ORM\Entity(repositoryClass: ProjectReportAliasRepository::class)]
#[ORM\Table(name: 'warehouse_project_report_alias')]
#[ORM\UniqueConstraint(name: 'uniq_project_report_alias_norm', columns: ['alias_normalized'])]
class ProjectReportAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Název v reportu (kanon). */
    #[ORM\Column(length: 255)]
    private string $canonicalLabel = '';

    /** Původní zápis v deníku, který se v reportu sloučí. */
    #[ORM\Column(length: 255)]
    private string $aliasLabel = '';

    /** {@see ProjectReportAliasService::normalize()} */
    #[ORM\Column(length: 255)]
    private string $aliasNormalized = '';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCanonicalLabel(): string
    {
        return $this->canonicalLabel;
    }

    public function setCanonicalLabel(string $canonicalLabel): static
    {
        $this->canonicalLabel = $canonicalLabel;

        return $this;
    }

    public function getAliasLabel(): string
    {
        return $this->aliasLabel;
    }

    public function setAliasLabel(string $aliasLabel): static
    {
        $this->aliasLabel = $aliasLabel;

        return $this;
    }

    public function getAliasNormalized(): string
    {
        return $this->aliasNormalized;
    }

    public function setAliasNormalized(string $aliasNormalized): static
    {
        $this->aliasNormalized = $aliasNormalized;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
