<?php

namespace App\Entity;

use App\Repository\WithdrawalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: WithdrawalRepository::class)]
#[ORM\Table(name: 'withdrawal')]
#[ORM\Index(columns: ['status'], name: 'idx_withdrawal_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_withdrawal_created_at')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_withdrawal_user_status')]
#[ORM\Index(columns: ['transaction_hash'], name: 'idx_withdrawal_tx_hash')]
#[ORM\HasLifecycleCallbacks]
class Withdrawal
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_BONUS = 'bonus';
    public const SOURCE_REFERRAL = 'referral';
    public const SOURCE_DEPOSIT = 'deposit';
    public const SOURCE_MIXED = 'mixed';

    public const NETWORK_TRC20 = 'TRC20';
    public const NETWORK_ERC20 = 'ERC20';

    public const CURRENCY_USDT = 'USDT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'withdrawals')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    #[Assert\Positive]
    #[Assert\GreaterThanOrEqual(value: 10, message: 'Minimum withdrawal is 10 USDT')]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $fee = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $netAmount; // amount - fee

    #[ORM\Column(length: 10, options: ['default' => self::CURRENCY_USDT])]
    #[Assert\Choice(choices: [self::CURRENCY_USDT])]
    private string $currency = self::CURRENCY_USDT;

    #[ORM\Column(length: 10, options: ['default' => self::NETWORK_TRC20])]
    #[Assert\Choice(choices: [self::NETWORK_TRC20, self::NETWORK_ERC20])]
    private string $network = self::NETWORK_TRC20;

    #[ORM\Column(length: 42)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 42)]
    #[Assert\Regex(pattern: '/^T[A-Za-z1-9]{33}$/', message: 'Invalid TRC-20 address format')]
    private string $toAddress;

    #[ORM\Column(length: 66, unique: true, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-fA-F0-9]{64}$/', message: 'Invalid transaction hash format')]
    private ?string $transactionHash = null;

    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_MIXED])]
    #[Assert\Choice(choices: [self::SOURCE_BONUS, self::SOURCE_REFERRAL, self::SOURCE_DEPOSIT, self::SOURCE_MIXED])]
    private string $source = self::SOURCE_MIXED;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Assert\Choice(choices: [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED
    ])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::JSON)]
    private array $approvals = [];

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 2])]
    #[Assert\Positive]
    private int $requiredApprovals = 2;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isManual = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresTwoFactor = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $twoFactorVerified = false;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\OneToOne(mappedBy: 'withdrawal', targetEntity: Transaction::class)]
    private ?Transaction $transaction = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $referenceId = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->referenceId = $this->generateReferenceId();
    }

    private function generateReferenceId(): string
    {
        return 'WD' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        if ($this->referenceId === null) {
            $this->referenceId = $this->generateReferenceId();
        }

        // Calculate net amount
        if ($this->amount && $this->fee) {
            $this->netAmount = bcsub($this->amount, $this->fee, 8);
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();

        // Recalculate net amount if needed
        if ($this->amount && $this->fee) {
            $this->netAmount = bcsub($this->amount, $this->fee, 8);
        }
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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getFee(): string
    {
        return $this->fee;
    }

    public function setFee(string $fee): static
    {
        $this->fee = $fee;
        return $this;
    }

    public function getNetAmount(): string
    {
        return $this->netAmount;
    }

    public function setNetAmount(string $netAmount): static
    {
        $this->netAmount = $netAmount;
        return $this;
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

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function setNetwork(string $network): static
    {
        $this->network = $network;
        return $this;
    }

    public function getToAddress(): string
    {
        return $this->toAddress;
    }

    public function setToAddress(string $toAddress): static
    {
        $this->toAddress = $toAddress;
        return $this;
    }

    public function getTransactionHash(): ?string
    {
        return $this->transactionHash;
    }

    public function setTransactionHash(?string $transactionHash): static
    {
        $this->transactionHash = $transactionHash;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        // Update timestamps based on status
        switch ($status) {
            case self::STATUS_APPROVED:
                if ($this->approvedAt === null) {
                    $this->approvedAt = new DateTimeImmutable();
                }
                break;
            case self::STATUS_PROCESSING:
                if ($this->processedAt === null) {
                    $this->processedAt = new DateTimeImmutable();
                }
                break;
            case self::STATUS_COMPLETED:
                if ($this->completedAt === null) {
                    $this->completedAt = new DateTimeImmutable();
                }
                break;
        }

        return $this;
    }

    public function getApprovals(): array
    {
        return $this->approvals;
    }

    public function setApprovals(array $approvals): static
    {
        $this->approvals = $approvals;
        return $this;
    }

    public function addApproval(User $admin): static
    {
        if (!$this->isApprovedBy($admin)) {
            $this->approvals[] = [
                'admin_id' => $admin->getId(),
                'admin_username' => $admin->getUsername(),
                'approved_at' => (new DateTimeImmutable())->format('c'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ];
        }

        // Check if we have enough approvals
        if (count($this->approvals) >= $this->requiredApprovals && $this->status === self::STATUS_AWAITING_APPROVAL) {
            $this->setStatus(self::STATUS_APPROVED);
        }

        return $this;
    }

    public function isApprovedBy(User $admin): bool
    {
        foreach ($this->approvals as $approval) {
            if ($approval['admin_id'] === $admin->getId()) {
                return true;
            }
        }
        return false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function needsMoreApprovals(): bool
    {
        return $this->getApprovalCount() < $this->requiredApprovals;
    }

    public function getApprovalCount(): int
    {
        return count($this->approvals);
    }

    public function getRequiredApprovals(): int
    {
        return $this->requiredApprovals;
    }

    public function setRequiredApprovals(int $requiredApprovals): static
    {
        $this->requiredApprovals = $requiredApprovals;
        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): static
    {
        $this->adminNote = $adminNote;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
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

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isManual(): bool
    {
        return $this->isManual;
    }

    public function setIsManual(bool $isManual): static
    {
        $this->isManual = $isManual;
        return $this;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->requiresTwoFactor;
    }

    public function setRequiresTwoFactor(bool $requiresTwoFactor): static
    {
        $this->requiresTwoFactor = $requiresTwoFactor;
        return $this;
    }

    public function isTwoFactorVerified(): bool
    {
        return $this->twoFactorVerified;
    }

    public function setTwoFactorVerified(bool $twoFactorVerified): static
    {
        $this->twoFactorVerified = $twoFactorVerified;
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

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    public function setReferenceId(?string $referenceId): static
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    // Status check methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeApproved(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_AWAITING_APPROVAL], true);
    }

    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_AWAITING_APPROVAL], true);
    }

    public function isAboveAutoApproveLimit(float $limit): bool
    {
        return bccomp($this->amount, (string)$limit, 8) > 0;
    }

    public function __toString(): string
    {
        return sprintf(
            'Withdrawal #%s: %s %s to %s (%s)',
            $this->referenceId ?? $this->id,
            $this->amount,
            $this->currency,
            substr($this->toAddress, 0, 10) . '...',
            $this->status
        );
    }
}