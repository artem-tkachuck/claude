<?php

namespace App\Entity;

use App\Repository\DepositRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DepositRepository::class)]
#[ORM\Table(name: 'deposit')]
#[ORM\Index(columns: ['transaction_hash'], name: 'idx_tx_hash')]
#[ORM\Index(columns: ['status'], name: 'idx_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_user_status')]
#[ORM\HasLifecycleCallbacks]
class Deposit
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMING = 'confirming';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const NETWORK_TRC20 = 'TRC20';
    public const NETWORK_ERC20 = 'ERC20';

    public const CURRENCY_USDT = 'USDT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'deposits')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    #[Assert\Positive]
    #[Assert\GreaterThanOrEqual(value: 100, message: 'Minimum deposit is 100 USDT')]
    private string $amount;

    #[ORM\Column(length: 10, options: ['default' => self::CURRENCY_USDT])]
    #[Assert\Choice(choices: [self::CURRENCY_USDT])]
    private string $currency = self::CURRENCY_USDT;

    #[ORM\Column(length: 10, options: ['default' => self::NETWORK_TRC20])]
    #[Assert\Choice(choices: [self::NETWORK_TRC20, self::NETWORK_ERC20])]
    private string $network = self::NETWORK_TRC20;

    #[ORM\Column(length: 66, unique: true, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-fA-F0-9]{64}$/', message: 'Invalid transaction hash format')]
    private ?string $transactionHash = null;

    #[ORM\Column(length: 42, nullable: true)]
    #[Assert\Length(exactly: 42)]
    private ?string $fromAddress = null;

    #[ORM\Column(length: 42)]
    #[Assert\Length(exactly: 42)]
    #[Assert\NotBlank]
    private string $toAddress;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Assert\Choice(choices: [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMING,
        self::STATUS_CONFIRMED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED
    ])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $confirmations = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 19])]
    #[Assert\Positive]
    private int $requiredConfirmations = 19;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 8, nullable: true)]
    private ?string $networkFee = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $blockNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $blockTime = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $bonusProcessed = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $referralProcessed = false;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\OneToOne(mappedBy: 'deposit', targetEntity: Transaction::class)]
    private ?Transaction $transaction = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = new DateTimeImmutable('+24 hours');
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        if ($this->expiresAt === null) {
            $this->expiresAt = new DateTimeImmutable('+24 hours');
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
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

    public function getTransactionHash(): ?string
    {
        return $this->transactionHash;
    }

    public function setTransactionHash(?string $transactionHash): static
    {
        $this->transactionHash = $transactionHash;
        return $this;
    }

    public function getFromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(?string $fromAddress): static
    {
        $this->fromAddress = $fromAddress;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        if ($status === self::STATUS_CONFIRMED && $this->confirmedAt === null) {
            $this->confirmedAt = new DateTimeImmutable();
        }

        return $this;
    }

    public function getConfirmations(): int
    {
        return $this->confirmations;
    }

    public function setConfirmations(int $confirmations): static
    {
        $this->confirmations = $confirmations;

        if ($confirmations >= $this->requiredConfirmations && $this->status === self::STATUS_CONFIRMING) {
            $this->setStatus(self::STATUS_CONFIRMED);
        }

        return $this;
    }

    public function getRequiredConfirmations(): int
    {
        return $this->requiredConfirmations;
    }

    public function setRequiredConfirmations(int $requiredConfirmations): static
    {
        $this->requiredConfirmations = $requiredConfirmations;
        return $this;
    }

    public function getNetworkFee(): ?string
    {
        return $this->networkFee;
    }

    public function setNetworkFee(?string $networkFee): static
    {
        $this->networkFee = $networkFee;
        return $this;
    }

    public function getBlockNumber(): ?int
    {
        return $this->blockNumber;
    }

    public function setBlockNumber(?int $blockNumber): static
    {
        $this->blockNumber = $blockNumber;
        return $this;
    }

    public function getBlockTime(): ?DateTimeImmutable
    {
        return $this->blockTime;
    }

    public function setBlockTime(?DateTimeImmutable $blockTime): static
    {
        $this->blockTime = $blockTime;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isBonusProcessed(): bool
    {
        return $this->bonusProcessed;
    }

    public function setBonusProcessed(bool $bonusProcessed): static
    {
        $this->bonusProcessed = $bonusProcessed;
        return $this;
    }

    public function isReferralProcessed(): bool
    {
        return $this->referralProcessed;
    }

    public function setReferralProcessed(bool $referralProcessed): static
    {
        $this->referralProcessed = $referralProcessed;
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

    public function isConfirming(): bool
    {
        return $this->status === self::STATUS_CONFIRMING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if ($this->expiresAt !== null && $this->expiresAt < new DateTimeImmutable() && $this->isPending()) {
            $this->setStatus(self::STATUS_EXPIRED);
            return true;
        }

        return false;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeProcessed(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMING], true);
    }

    public function getConfirmationProgress(): float
    {
        if ($this->requiredConfirmations === 0) {
            return 100.0;
        }

        return min(100, ($this->confirmations / $this->requiredConfirmations) * 100);
    }

    public function __toString(): string
    {
        return sprintf(
            'Deposit #%d: %s %s (%s)',
            $this->id,
            $this->amount,
            $this->currency,
            $this->status
        );
    }
}