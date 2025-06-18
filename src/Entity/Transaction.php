<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transaction')]
#[ORM\Index(columns: ['type'], name: 'idx_transaction_type')]
#[ORM\Index(columns: ['status'], name: 'idx_transaction_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_transaction_created_at')]
#[ORM\Index(columns: ['user_id', 'type'], name: 'idx_transaction_user_type')]
#[ORM\Index(columns: ['reference_id'], name: 'idx_transaction_reference')]
#[ORM\HasLifecycleCallbacks]
class Transaction
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_REFERRAL_BONUS = 'referral_bonus';
    public const TYPE_FEE = 'fee';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERSED = 'reversed';

    public const BALANCE_TYPE_DEPOSIT = 'deposit';
    public const BALANCE_TYPE_BONUS = 'bonus';
    public const BALANCE_TYPE_REFERRAL = 'referral';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::TYPE_DEPOSIT,
        self::TYPE_WITHDRAWAL,
        self::TYPE_BONUS,
        self::TYPE_REFERRAL_BONUS,
        self::TYPE_FEE,
        self::TYPE_ADJUSTMENT,
        self::TYPE_TRANSFER_IN,
        self::TYPE_TRANSFER_OUT
    ])]
    private string $type;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_COMPLETED])]
    #[Assert\Choice(choices: [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_REVERSED
    ])]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    #[Assert\NotBlank]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, nullable: true)]
    private ?string $fee = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    private string $currency;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::BALANCE_TYPE_DEPOSIT,
        self::BALANCE_TYPE_BONUS,
        self::BALANCE_TYPE_REFERRAL
    ])]
    private string $balanceType;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $balanceBefore;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $balanceAfter;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $referenceId;

    #[ORM\OneToOne(inversedBy: 'transaction', targetEntity: Deposit::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Deposit $deposit = null;

    #[ORM\OneToOne(inversedBy: 'transaction', targetEntity: Withdrawal::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Withdrawal $withdrawal = null;

    #[ORM\ManyToOne(targetEntity: Bonus::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Bonus $bonus = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?self $relatedTransaction = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isReversed = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $processedBy = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->referenceId = $this->generateReferenceId();
    }

    private function generateReferenceId(): string
    {
        $prefix = match ($this->type ?? 'TX') {
            self::TYPE_DEPOSIT => 'DEP',
            self::TYPE_WITHDRAWAL => 'WTH',
            self::TYPE_BONUS => 'BON',
            self::TYPE_REFERRAL_BONUS => 'REF',
            self::TYPE_FEE => 'FEE',
            self::TYPE_ADJUSTMENT => 'ADJ',
            self::TYPE_TRANSFER_IN => 'TIN',
            self::TYPE_TRANSFER_OUT => 'OUT',
            default => 'TXN',
        };

        return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        if ($this->referenceId === null) {
            $this->referenceId = $this->generateReferenceId();
        }

        if ($this->status === self::STATUS_COMPLETED && $this->completedAt === null) {
            $this->completedAt = new DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();

        if ($this->status === self::STATUS_COMPLETED && $this->completedAt === null) {
            $this->completedAt = new DateTimeImmutable();
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

        if ($status === self::STATUS_COMPLETED && $this->completedAt === null) {
            $this->completedAt = new DateTimeImmutable();
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

    public function getFee(): ?string
    {
        return $this->fee;
    }

    public function setFee(?string $fee): static
    {
        $this->fee = $fee;
        return $this;
    }

    public function getNetAmount(): string
    {
        if ($this->fee === null) {
            return $this->amount;
        }

        return bcsub($this->amount, $this->fee, 8);
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getBalanceType(): string
    {
        return $this->balanceType;
    }

    public function setBalanceType(string $balanceType): static
    {
        $this->balanceType = $balanceType;
        return $this;
    }

    public function getBalanceBefore(): string
    {
        return $this->balanceBefore;
    }

    public function setBalanceBefore(string $balanceBefore): static
    {
        $this->balanceBefore = $balanceBefore;
        return $this;
    }

    public function getBalanceAfter(): string
    {
        return $this->balanceAfter;
    }

    public function setBalanceAfter(string $balanceAfter): static
    {
        $this->balanceAfter = $balanceAfter;
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

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function setReferenceId(string $referenceId): static
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    public function getDeposit(): ?Deposit
    {
        return $this->deposit;
    }

    public function setDeposit(?Deposit $deposit): static
    {
        $this->deposit = $deposit;
        return $this;
    }

    public function getWithdrawal(): ?Withdrawal
    {
        return $this->withdrawal;
    }

    public function setWithdrawal(?Withdrawal $withdrawal): static
    {
        $this->withdrawal = $withdrawal;
        return $this;
    }

    public function getBonus(): ?Bonus
    {
        return $this->bonus;
    }

    public function setBonus(?Bonus $bonus): static
    {
        $this->bonus = $bonus;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
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

    public function getRelatedTransaction(): ?self
    {
        return $this->relatedTransaction;
    }

    public function setRelatedTransaction(?self $relatedTransaction): static
    {
        $this->relatedTransaction = $relatedTransaction;
        return $this;
    }

    public function isReversed(): bool
    {
        return $this->isReversed;
    }

    public function setIsReversed(bool $isReversed): static
    {
        $this->isReversed = $isReversed;
        return $this;
    }

    public function getProcessedBy(): ?User
    {
        return $this->processedBy;
    }

    public function setProcessedBy(?User $processedBy): static
    {
        $this->processedBy = $processedBy;
        return $this;
    }

    // Helper methods

    public function isCredit(): bool
    {
        return in_array($this->type, [
            self::TYPE_DEPOSIT,
            self::TYPE_BONUS,
            self::TYPE_REFERRAL_BONUS,
            self::TYPE_TRANSFER_IN
        ], true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeReversed(): bool
    {
        return $this->status === self::STATUS_COMPLETED &&
            !$this->isReversed &&
            in_array($this->type, [
                self::TYPE_DEPOSIT,
                self::TYPE_BONUS,
                self::TYPE_REFERRAL_BONUS,
                self::TYPE_ADJUSTMENT
            ], true);
    }

    public function __toString(): string
    {
        return sprintf(
            'Transaction #%s: %s %s %s (%s)',
            $this->referenceId,
            $this->getTypeLabel(),
            $this->getFormattedAmount(),
            $this->currency,
            $this->getStatusLabel()
        );
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_DEPOSIT => 'Deposit',
            self::TYPE_WITHDRAWAL => 'Withdrawal',
            self::TYPE_BONUS => 'Daily Bonus',
            self::TYPE_REFERRAL_BONUS => 'Referral Bonus',
            self::TYPE_FEE => 'Transaction Fee',
            self::TYPE_ADJUSTMENT => 'Balance Adjustment',
            self::TYPE_TRANSFER_IN => 'Transfer In',
            self::TYPE_TRANSFER_OUT => 'Transfer Out',
            default => 'Transaction',
        };
    }

    public function getFormattedAmount(): string
    {
        $sign = $this->isDebit() ? '-' : '+';
        return $sign . $this->amount . ' ' . $this->currency;
    }

    public function isDebit(): bool
    {
        return in_array($this->type, [
            self::TYPE_WITHDRAWAL,
            self::TYPE_FEE,
            self::TYPE_TRANSFER_OUT
        ], true);
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_REVERSED => 'Reversed',
            default => 'Unknown',
        };
    }
}