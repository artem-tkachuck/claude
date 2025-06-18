<?php

namespace App\Entity;

use App\Repository\SystemSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SystemSettingsRepository::class)]
#[ORM\Table(name: 'system_settings')]
#[ORM\Index(columns: ['category'], name: 'idx_settings_category')]
#[ORM\Index(columns: ['is_public'], name: 'idx_settings_public')]
#[ORM\UniqueConstraint(name: 'uniq_setting_key', columns: ['setting_key'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['key'], message: 'This setting key already exists')]
class SystemSettings
{
    // Categories
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_BLOCKCHAIN = 'blockchain';
    public const CATEGORY_BONUS = 'bonus';
    public const CATEGORY_REFERRAL = 'referral';
    public const CATEGORY_WITHDRAWAL = 'withdrawal';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_TELEGRAM = 'telegram';
    public const CATEGORY_EMAIL = 'email';
    public const CATEGORY_MAINTENANCE = 'maintenance';

    // Data types
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_JSON = 'json';
    public const TYPE_DATETIME = 'datetime';

    // Common settings keys
    public const KEY_MAINTENANCE_MODE = 'maintenance.enabled';
    public const KEY_MAINTENANCE_MESSAGE = 'maintenance.message';
    public const KEY_MIN_DEPOSIT = 'deposit.minimum_amount';
    public const KEY_MIN_WITHDRAWAL = 'withdrawal.minimum_amount';
    public const KEY_WITHDRAWAL_FEE = 'withdrawal.fee_percentage';
    public const KEY_AUTO_APPROVE_LIMIT = 'withdrawal.auto_approve_limit';
    public const KEY_DAILY_WITHDRAWAL_LIMIT = 'withdrawal.daily_limit';
    public const KEY_BONUS_PERCENTAGE = 'bonus.distribution_percentage';
    public const KEY_COMPANY_PERCENTAGE = 'bonus.company_percentage';
    public const KEY_REFERRAL_ENABLED = 'referral.enabled';
    public const KEY_REFERRAL_LEVELS = 'referral.max_levels';
    public const KEY_REFERRAL_LEVEL1 = 'referral.level1_percentage';
    public const KEY_REFERRAL_LEVEL2 = 'referral.level2_percentage';
    public const KEY_HOT_WALLET_THRESHOLD = 'blockchain.hot_wallet_threshold';
    public const KEY_REQUIRED_CONFIRMATIONS = 'blockchain.required_confirmations';
    public const KEY_ALLOWED_COUNTRIES = 'security.allowed_countries';
    public const KEY_BLOCKED_COUNTRIES = 'security.blocked_countries';
    public const KEY_MAX_LOGIN_ATTEMPTS = 'security.max_login_attempts';
    public const KEY_LOCKOUT_DURATION = 'security.lockout_duration';
    public const KEY_REQUIRE_2FA_ADMINS = 'security.require_2fa_admins';
    public const KEY_REQUIRE_2FA_WITHDRAWALS = 'security.require_2fa_withdrawals';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[a-z0-9._]+$/', message: 'Setting key must contain only lowercase letters, numbers, dots and underscores')]
    private string $key;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::TYPE_STRING,
        self::TYPE_INTEGER,
        self::TYPE_FLOAT,
        self::TYPE_BOOLEAN,
        self::TYPE_JSON,
        self::TYPE_DATETIME
    ])]
    private string $type = self::TYPE_STRING;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::CATEGORY_GENERAL,
        self::CATEGORY_BLOCKCHAIN,
        self::CATEGORY_BONUS,
        self::CATEGORY_REFERRAL,
        self::CATEGORY_WITHDRAWAL,
        self::CATEGORY_SECURITY,
        self::CATEGORY_TELEGRAM,
        self::CATEGORY_EMAIL,
        self::CATEGORY_MAINTENANCE
    ])]
    private string $category;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $defaultValue = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isPublic = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isEncrypted = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEditable = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $validation = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function setOptions(?array $options): static
    {
        $this->options = $options;
        return $this;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?string $defaultValue): static
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;
        return $this;
    }

    public function isEncrypted(): bool
    {
        return $this->isEncrypted;
    }

    public function setIsEncrypted(bool $isEncrypted): static
    {
        $this->isEncrypted = $isEncrypted;
        return $this;
    }

    public function isEditable(): bool
    {
        return $this->isEditable;
    }

    public function setIsEditable(bool $isEditable): static
    {
        $this->isEditable = $isEditable;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getValidation(): ?array
    {
        return $this->validation;
    }

    public function setValidation(?array $validation): static
    {
        $this->validation = $validation;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // Helper methods
    public function getTypedValue(): mixed
    {
        if ($this->value === null) {
            return $this->getTypedDefaultValue();
        }

        return match ($this->type) {
            self::TYPE_INTEGER => (int) $this->value,
            self::TYPE_FLOAT => (float) $this->value,
            self::TYPE_BOOLEAN => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_JSON => json_decode($this->value, true),
            self::TYPE_DATETIME => new \DateTimeImmutable($this->value),
            default => $this->value,
        };
    }

    public function setTypedValue(mixed $value): static
    {
        $this->value = match ($this->type) {
            self::TYPE_INTEGER => (string) (int) $value,
            self::TYPE_FLOAT => (string) (float) $value,
            self::TYPE_BOOLEAN => $value ? '1' : '0',
            self::TYPE_JSON => json_encode($value),
            self::TYPE_DATETIME => $value instanceof \DateTimeInterface ? $value->format('c') : $value,
            default => (string) $value,
        };

        return $this;
    }

    public function getTypedDefaultValue(): mixed
    {
        if ($this->defaultValue === null) {
            return match ($this->type) {
                self::TYPE_INTEGER => 0,
                self::TYPE_FLOAT => 0.0,
                self::TYPE_BOOLEAN => false,
                self::TYPE_JSON => [],
                default => null,
            };
        }

        return match ($this->type) {
            self::TYPE_INTEGER => (int) $this->defaultValue,
            self::TYPE_FLOAT => (float) $this->defaultValue,
            self::TYPE_BOOLEAN => filter_var($this->defaultValue, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_JSON => json_decode($this->defaultValue, true),
            self::TYPE_DATETIME => new \DateTimeImmutable($this->defaultValue),
            default => $this->defaultValue,
        };
    }

    public function getCategoryLabel(): string
    {
        return match ($this->category) {
            self::CATEGORY_GENERAL => 'General',
            self::CATEGORY_BLOCKCHAIN => 'Blockchain',
            self::CATEGORY_BONUS => 'Bonus',
            self::CATEGORY_REFERRAL => 'Referral',
            self::CATEGORY_WITHDRAWAL => 'Withdrawal',
            self::CATEGORY_SECURITY => 'Security',
            self::CATEGORY_TELEGRAM => 'Telegram',
            self::CATEGORY_EMAIL => 'Email',
            self::CATEGORY_MAINTENANCE => 'Maintenance',
            default => 'Other',
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_STRING => 'Text',
            self::TYPE_INTEGER => 'Integer',
            self::TYPE_FLOAT => 'Decimal',
            self::TYPE_BOOLEAN => 'Yes/No',
            self::TYPE_JSON => 'JSON',
            self::TYPE_DATETIME => 'Date/Time',
            default => 'Unknown',
        };
    }

    public function getFormType(): string
    {
        return match ($this->type) {
            self::TYPE_INTEGER => 'integer',
            self::TYPE_FLOAT => 'number',
            self::TYPE_BOOLEAN => 'checkbox',
            self::TYPE_JSON => 'textarea',
            self::TYPE_DATETIME => 'datetime',
            default => 'text',
        };
    }

    public function validateValue(mixed $value): bool
    {
        if ($this->validation === null) {
            return true;
        }

        // Basic type validation
        $isValid = match ($this->type) {
            self::TYPE_INTEGER => is_numeric($value) && (int) $value == $value,
            self::TYPE_FLOAT => is_numeric($value),
            self::TYPE_BOOLEAN => is_bool($value) || in_array($value, ['0', '1', 'true', 'false'], true),
            self::TYPE_JSON => is_array($value) || (is_string($value) && json_decode($value) !== null),
            self::TYPE_DATETIME => $value instanceof \DateTimeInterface || (is_string($value) && strtotime($value) !== false),
            default => true,
        };

        if (!$isValid) {
            return false;
        }

        // Additional validation rules
        if (isset($this->validation['min'])) {
            $numericValue = is_numeric($value) ? (float) $value : 0;
            if ($numericValue < $this->validation['min']) {
                return false;
            }
        }

        if (isset($this->validation['max'])) {
            $numericValue = is_numeric($value) ? (float) $value : 0;
            if ($numericValue > $this->validation['max']) {
                return false;
            }
        }

        if (isset($this->validation['pattern']) && is_string($value)) {
            if (!preg_match($this->validation['pattern'], $value)) {
                return false;
            }
        }

        if (isset($this->validation['choices']) && is_array($this->validation['choices'])) {
            if (!in_array($value, $this->validation['choices'], true)) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->label, $this->key);
    }
}