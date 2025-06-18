<?php

namespace App\Entity;

use App\Repository\BonusRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BonusRepository::class)]
#[ORM\Table(name: 'bonus')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_bonus_user_created')]
#[ORM\Index(columns: ['type'], name: 'idx_bonus_type')]
#[ORM\Index(columns: ['status'], name: 'idx_bonus_status')]
#[ORM\Index(columns: ['calculated_at'], name: 'idx_bonus_calculated_at')]
#[ORM\HasLifecycleCallbacks]
class Bonus
{
    public const TYPE_DAILY = 'daily';
    public const TYPE_REFERRAL = 'referral';
    public const TYPE_SPECIAL = 'special';
    public const TYPE_ACHIEVEMENT = 'achievement';
    public const TYPE_PROMOTIONAL = 'promotional';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_DISTRIBUTED = 'distributed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'bonuses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_DAILY])]
    #[Assert\Choice(choices: [
        self::TYPE_DAILY,
        self::TYPE_REFERRAL,
        self::TYPE_SPECIAL,
        self::TYPE_ACHIEVEMENT,
        self::TYPE_PROMOTIONAL
    ])]
    private string $type = self::TYPE_DAILY;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Assert\Choice(choices: [
        self::STATUS_PENDING,
        self::STATUS_CALCULATED,
        self::STATUS_DISTRIBUTED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED
    ])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    #[Assert\Positive]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $depositBalance = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $totalProfit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $distributionPool = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $percentage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bonusDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $calculatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $distributedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $referralFrom = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $referralLevel = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\OneToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Transaction $transaction = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $batchId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->calculatedAt = new \DateTimeImmutable();
        $this->bonusDate = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        if ($this->calculatedAt === null) {
            $this->calculatedAt = new \DateTimeImmutable();
        }

        if ($this->bonusDate === null) {
            $this->bonusDate = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();

        if ($this->status === self::STATUS_DISTRIBUTED && $this->distributedAt === null) {
            $this->distributedAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        if ($status === self::STATUS_DISTRIBUTED && $this->distributedAt === null) {
            $this->distributedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getDepositBalance(): ?string
    {
        return $this->depositBalance;
    }

    public function setDepositBalance(?string $depositBalance): static
    {
        $this->depositBalance = $depositBalance;
        return $this;
    }

    public function getTotalProfit(): ?string
    {
        return $this->totalProfit;
    }

    public function setTotalProfit(?string $totalProfit): static
    {
        $this->totalProfit = $totalProfit;
        return $this;
    }

    public function getDistributionPool(): ?string
    {
        return $this->distributionPool;
    }

    public function setDistributionPool(?string $distributionPool): static
    {
        $this->distributionPool = $distributionPool;
        return $this;
    }

    public function getPercentage(): ?string
    {
        return $this->percentage;
    }

    public function setPercentage(?string $percentage): static
    {
        $this->percentage = $percentage;
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

    public function getBonusDate(): ?\DateTimeImmutable
    {
        return $this->bonusDate;
    }

    public function setBonusDate(?\DateTimeImmutable $bonusDate): static
    {
        $this->bonusDate = $bonusDate;
        return $this;
    }

    public function getCalculatedAt(): ?\DateTimeImmutable
    {
        return $this->calculatedAt;
    }

    public function setCalculatedAt(\DateTimeImmutable $calculatedAt): static
    {
        $this->calculatedAt = $calculatedAt;
        return $this;
    }

    public function getDistributedAt(): ?\DateTimeImmutable
    {
        return $this->distributedAt;
    }

    public function setDistributedAt(?\DateTimeImmutable $distributedAt): static
    {
        $this->distributedAt = $distributedAt;
        return $this;
    }

    public function getReferralFrom(): ?User
    {
        return $this->referralFrom;
    }

    public function setReferralFrom(?User $referralFrom): static
    {
        $this->referralFrom = $referralFrom;
        return $this;
    }

    public function getReferralLevel(): ?int
    {
        return $this->referralLevel;
    }

    public function setReferralLevel(?int $referralLevel): static
    {
        $this->referralLevel = $referralLevel;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): static
    {
        $this->failureReason = $failureReason;
        return $this;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): static
    {
        $this->transaction = $transaction;
        return $this;
    }

    public function getBatchId(): ?int
    {
        return $this->batchId;
    }

    public function setBatchId(?int $batchId): static
    {
        $this->batchId = $batchId;
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
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCalculated(): bool
    {
        return $this->status === self::STATUS_CALCULATED;
    }

    public function isDistributed(): bool
    {
        return $this->status === self::STATUS_DISTRIBUTED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeDistributed(): bool
    {
        return $this->status === self::STATUS_CALCULATED && $this->transaction === null;
    }

    public function isDaily(): bool
    {
        return $this->type === self::TYPE_DAILY;
    }

    public function isReferral(): bool
    {
        return $this->type === self::TYPE_REFERRAL;
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_DAILY => 'Daily Bonus',
            self::TYPE_REFERRAL => 'Referral Bonus',
            self::TYPE_SPECIAL => 'Special Bonus',
            self::TYPE_ACHIEVEMENT => 'Achievement Bonus',
            self::TYPE_PROMOTIONAL => 'Promotional Bonus',
            default => 'Bonus',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_CALCULATED => 'Calculated',
            self::STATUS_DISTRIBUTED => 'Distributed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }

    public function calculatePercentage(): ?string
    {
        if ($this->depositBalance === null || $this->distributionPool === null) {
            return null;
        }

        if (bccomp($this->distributionPool, '0', 8) === 0) {
            return '0';
        }

        return bcdiv($this->depositBalance, $this->distributionPool, 8);
    }

    public function getFormattedDescription(): string
    {
        if ($this->description !== null) {
            return $this->description;
        }

        if ($this->isDaily()) {
            return sprintf(
                'Daily bonus based on %s USDT deposit (%s%%)',
                $this->depositBalance ?? '0',
                $this->percentage ? bcmul($this->percentage, '100', 2) : '0'
            );
        }

        if ($this->isReferral()) {
            $from = $this->referralFrom ? $this->referralFrom->getUsername() : 'Unknown';
            return sprintf(
                'Level %d referral bonus from %s',
                $this->referralLevel ?? 0,
                $from
            );
        }

        return $this->getTypeLabel();
    }

    public function __toString(): string
    {
        return sprintf(
            'Bonus #%d: %s %s USDT for %s (%s)',
            $this->id,
            $this->getTypeLabel(),
            $this->amount,
            $this->user ? $this->user->getUsername() : 'Unknown',
            $this->getStatusLabel()
        );
    }
}