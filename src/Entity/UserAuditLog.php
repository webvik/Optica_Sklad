<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserAuditLogRepository::class)]
#[ORM\Table(name: 'user_audit_log')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_user_audit_log_occurred')]
#[ORM\Index(columns: ['username'], name: 'idx_user_audit_log_username')]
class UserAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actingUser = null;

    #[ORM\Column(length: 180)]
    private string $username = '';

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rolesSnapshot = null;

    #[ORM\Column(length: 8)]
    private string $method = '';

    #[ORM\Column(length: 2048)]
    private string $path = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $routeName = null;

    #[ORM\Column(nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgentFragment = null;

    public static function fromRequestOutcome(
        User $actingUser,
        string $method,
        string $path,
        ?string $routeName,
        ?int $httpStatus,
        ?string $ip,
        ?string $userAgent,
    ): self {
        $e = new self();
        $e->occurredAt = new \DateTimeImmutable('now');
        $e->actingUser = $actingUser;
        $e->username = $actingUser->getUserIdentifier();
        $e->rolesSnapshot = $actingUser->getAssignedRoles();
        $e->method = $method;
        $e->path = $path;
        $e->routeName = $routeName;
        $e->httpStatus = $httpStatus;
        $e->ip = (null !== $ip && '' !== $ip) ? $ip : null;
        if (null !== $userAgent && '' !== $userAgent) {
            $e->userAgentFragment = mb_strlen($userAgent, 'UTF-8') <= 512
                ? $userAgent
                : mb_substr($userAgent, 0, 512, 'UTF-8');
        }

        return $e;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /** @return list<string>|null */
    public function getRolesSnapshot(): ?array
    {
        return $this->rolesSnapshot;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgentFragment(): ?string
    {
        return $this->userAgentFragment;
    }
}
