<?php

namespace App\Entity;

use App\Repository\EventLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventLogRepository::class)]
#[ORM\Table(name: 'event_log')]
#[ORM\Index(columns: ['event_type'], name: 'idx_event_type')]
#[ORM\Index(columns: ['entity_type', 'entity_id'], name: 'idx_entity')]
#[ORM\Index(columns: ['user_id'], name: 'idx_event_user')]
#[ORM\Index(columns: ['created_at'], name: 'idx_event_created')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_event_ip')]
#[ORM\Index(columns: ['severity'], name: 'idx_event_severity')]
#[ORM\HasLifecycleCallbacks]
class EventLog
{
    // Event types
    public const TYPE_USER_REGISTERED = 'user.registered';
    public const TYPE_USER_LOGIN = 'user.login';
    public const TYPE_USER_LOGOUT = 'user.logout';
    public const TYPE_USER_LOGIN_FAILED = 'user.login_failed';
    public const TYPE_USER_LOCKED = 'user.locked';
    public const TYPE_USER_UNLOCKED = 'user.unlocked';
    public const TYPE_USER_UPDATED = 'user.updated';
    public const TYPE_USER_2FA_ENABLED = 'user.2fa_enabled';
    public const TYPE_USER_2FA_DISABLED = 'user.2fa_disabled';

    public const TYPE_DEPOSIT_CREATED = 'deposit.created';
    public const TYPE_DEPOSIT_CONFIRMED = 'deposit.confirmed';
    public const TYPE_DEPOSIT_FAILED = 'deposit.failed';

    public const TYPE_WITHDRAWAL_CREATED = 'withdrawal.created';
    public const TYPE_WITHDRAWAL_APPROVED = 'withdrawal.approved';
    public const TYPE_WITHDRAWAL_REJECTED = 'withdrawal.rejected';
    public const TYPE_WITHDRAWAL_PROCESSED = 'withdrawal.processed';
    public const TYPE_WITHDRAWAL_COMPLETED = 'withdrawal.completed';
    public const TYPE_WITHDRAWAL_FAILED = 'withdrawal.failed';

    public const TYPE_BONUS_CALCULATED = 'bonus.calculated';
    public const TYPE_BONUS_DISTRIBUTED = 'bonus.distributed';

    public const TYPE_REFERRAL_REGISTERED = 'referral.registered';
    public const TYPE_REFERRAL_BONUS_EARNED = 'referral.bonus_earned';

    public const TYPE_ADMIN_ACTION = 'admin.action';
    public const TYPE_ADMIN_LOGIN = 'admin.login';
    public const TYPE_ADMIN_SETTINGS_CHANGED = 'admin.settings_changed';

    public const TYPE_SECURITY_ALERT = 'security.alert';
    public const TYPE_SECURITY_BLOCKED_IP = 'security.blocked_ip';
    public const TYPE_SECURITY_SUSPICIOUS_ACTIVITY = 'security.suspicious_activity';

    public const TYPE_SYSTEM_ERROR = 'system.error';
    public const TYPE_SYSTEM_WARNING = 'system.warning';
    public const TYPE_SYSTEM_MAINTENANCE = 'system.maintenance';

    // Severity levels
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $eventType;

    #[ORM\Column(length: 20, options: ['default' => self::SEVERITY_INFO])]
    private string $severity = self::SEVERITY_INFO;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'eventLogs')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $performedBy = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldData = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newData = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $httpMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $requestUri = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getPerformedBy(): ?User
    {
        return $this->performedBy;
    }

    public function setPerformedBy(?User $performedBy): static
    {
        $this->performedBy = $performedBy;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(?string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function getOldData(): ?array
    {
        return $this->oldData;
    }

    public function setOldData(?array $oldData): static
    {
        $this->oldData = $oldData;
        return $this;
    }

    public function getNewData(): ?array
    {
        return $this->newData;
    }

    public function setNewData(?array $newData): static
    {
        $this->newData = $newData;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): static
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): static
    {
        $this->requestId = $requestId;
        return $this;
    }

    public function getHttpMethod(): ?string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(?string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;
        return $this;
    }

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    public function setRequestUri(?string $requestUri): static
    {
        $this->requestUri = $requestUri;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Helper methods
    public function isInfo(): bool
    {
        return $this->severity === self::SEVERITY_INFO;
    }

    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function isSecurityEvent(): bool
    {
        return str_starts_with($this->eventType, 'security.');
    }

    public function isAdminEvent(): bool
    {
        return str_starts_with($this->eventType, 'admin.');
    }

    public function isUserEvent(): bool
    {
        return str_starts_with($this->eventType, 'user.');
    }

    public function isSystemEvent(): bool
    {
        return str_starts_with($this->eventType, 'system.');
    }

    public function getEventCategory(): string
    {
        $parts = explode('.', $this->eventType);
        return $parts[0] ?? 'unknown';
    }

    public function getEventAction(): string
    {
        $parts = explode('.', $this->eventType);
        return $parts[1] ?? 'unknown';
    }

    public function getSeverityLabel(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'Information',
            self::SEVERITY_WARNING => 'Warning',
            self::SEVERITY_ERROR => 'Error',
            self::SEVERITY_CRITICAL => 'Critical',
            default => 'Unknown',
        };
    }

    public function getSeverityColor(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => '#17a2b8',
            self::SEVERITY_WARNING => '#ffc107',
            self::SEVERITY_ERROR => '#dc3545',
            self::SEVERITY_CRITICAL => '#721c24',
            default => '#6c757d',
        };
    }

    public function getChangedFields(): array
    {
        if ($this->oldData === null || $this->newData === null) {
            return [];
        }

        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($this->oldData), array_keys($this->newData)));

        foreach ($allKeys as $key) {
            $oldValue = $this->oldData[$key] ?? null;
            $newValue = $this->newData[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'severity' => $this->severity,
            'message' => $this->message,
            'user_id' => $this->user?->getId(),
            'user_username' => $this->user?->getUsername(),
            'performed_by_id' => $this->performedBy?->getId(),
            'performed_by_username' => $this->performedBy?->getUsername(),
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'context' => $this->context,
            'ip_address' => $this->ipAddress,
            'created_at' => $this->createdAt?->format('c'),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            '[%s] %s: %s',
            $this->createdAt?->format('Y-m-d H:i:s'),
            strtoupper($this->severity),
            $this->message
        );
    }
}