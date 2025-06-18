<?php

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\Index(columns: ['telegram_id'], name: 'idx_telegram_id')]
#[ORM\Index(columns: ['referral_code'], name: 'idx_referral_code')]
#[ORM\Index(columns: ['email'], name: 'idx_email')]
#[ORM\Index(columns: ['is_active', 'created_at'], name: 'idx_active_created')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'This email is already registered')]
#[UniqueEntity(fields: ['telegramId'], message: 'This Telegram account is already registered')]
#[UniqueEntity(fields: ['referralCode'], message: 'Referral code generation error')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::BIGINT, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    private ?int $telegramId = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 180)]
    private ?string $username = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $referralCode = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'referrals')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $referrer = null;

    #[ORM\OneToMany(mappedBy: 'referrer', targetEntity: self::class)]
    private Collection $referrals;

    #[ORM\Column(length: 42, nullable: true)]
    #[Assert\Length(exactly: 42)]
    #[Assert\Regex(pattern: '/^T[A-Za-z1-9]{33}$/', message: 'Invalid TRC-20 address format')]
    private ?string $walletAddress = null;

    #[ORM\Column(length: 10, options: ['default' => 'en'])]
    #[Assert\Choice(choices: ['en', 'uk', 'ru'])]
    private string $language = 'en';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $twoFactorEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $twoFactorSecret = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $autoWithdrawal = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $depositBalance = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $bonusBalance = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $referralBalance = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $totalDeposited = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $totalWithdrawn = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $totalBonusEarned = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    private string $totalReferralEarned = '0';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $depositUnlockAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $firstDepositAt = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Deposit::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $deposits;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Withdrawal::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $withdrawals;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Transaction::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $transactions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Bonus::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $bonuses;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: EventLog::class)]
    private Collection $eventLogs;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $failedLoginAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->referrals = new ArrayCollection();
        $this->deposits = new ArrayCollection();
        $this->withdrawals = new ArrayCollection();
        $this->transactions = new ArrayCollection();
        $this->bonuses = new ArrayCollection();
        $this->eventLogs = new ArrayCollection();
        $this->referralCode = $this->generateReferralCode();
    }

    private function generateReferralCode(): string
    {
        return substr(bin2hex(random_bytes(16)), 0, 10);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->lastActivityAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->lastActivityAt = new DateTimeImmutable();
    }

    public function getTelegramId(): ?int
    {
        return $this->telegramId;
    }

    public function setTelegramId(int $telegramId): static
    {
        $this->telegramId = $telegramId;
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string)$this->username;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getReferralCode(): ?string
    {
        return $this->referralCode;
    }

    public function setReferralCode(string $referralCode): static
    {
        $this->referralCode = $referralCode;
        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getReferrals(): Collection
    {
        return $this->referrals;
    }

    public function addReferral(self $referral): static
    {
        if (!$this->referrals->contains($referral)) {
            $this->referrals->add($referral);
            $referral->setReferrer($this);
        }
        return $this;
    }

    public function removeReferral(self $referral): static
    {
        if ($this->referrals->removeElement($referral)) {
            if ($referral->getReferrer() === $this) {
                $referral->setReferrer(null);
            }
        }
        return $this;
    }

    public function getReferrer(): ?self
    {
        return $this->referrer;
    }

    public function setReferrer(?self $referrer): static
    {
        $this->referrer = $referrer;
        return $this;
    }

    public function getActiveReferralsCount(): int
    {
        return $this->referrals->filter(fn($referral) => $referral->getFirstDepositAt() !== null)->count();
    }

    public function getFirstDepositAt(): ?DateTimeImmutable
    {
        return $this->firstDepositAt;
    }

    public function setFirstDepositAt(?DateTimeImmutable $firstDepositAt): static
    {
        $this->firstDepositAt = $firstDepositAt;
        return $this;
    }

    public function getWalletAddress(): ?string
    {
        return $this->walletAddress;
    }

    public function setWalletAddress(?string $walletAddress): static
    {
        $this->walletAddress = $walletAddress;
        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function setTwoFactorEnabled(bool $twoFactorEnabled): static
    {
        $this->twoFactorEnabled = $twoFactorEnabled;
        return $this;
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->twoFactorSecret;
    }

    public function setTwoFactorSecret(?string $twoFactorSecret): static
    {
        $this->twoFactorSecret = $twoFactorSecret;
        return $this;
    }

    public function isAutoWithdrawal(): bool
    {
        return $this->autoWithdrawal;
    }

    public function setAutoWithdrawal(bool $autoWithdrawal): static
    {
        $this->autoWithdrawal = $autoWithdrawal;
        return $this;
    }

    public function getDepositBalance(): string
    {
        return $this->depositBalance;
    }

    public function setDepositBalance(string $depositBalance): static
    {
        $this->depositBalance = $depositBalance;
        return $this;
    }

    public function getBonusBalance(): string
    {
        return $this->bonusBalance;
    }

    public function setBonusBalance(string $bonusBalance): static
    {
        $this->bonusBalance = $bonusBalance;
        return $this;
    }

    public function getReferralBalance(): string
    {
        return $this->referralBalance;
    }

    public function setReferralBalance(string $referralBalance): static
    {
        $this->referralBalance = $referralBalance;
        return $this;
    }

    public function getTotalBalance(): string
    {
        return bcadd(bcadd($this->depositBalance, $this->bonusBalance, 8), $this->referralBalance, 8);
    }

    public function getAvailableForWithdrawal(): string
    {
        return bcadd($this->bonusBalance, $this->referralBalance, 8);
    }

    public function canWithdrawDeposit(): bool
    {
        if ($this->depositUnlockAt === null) {
            return false;
        }
        return $this->depositUnlockAt <= new DateTimeImmutable();
    }

    public function getTotalDeposited(): string
    {
        return $this->totalDeposited;
    }

    public function setTotalDeposited(string $totalDeposited): static
    {
        $this->totalDeposited = $totalDeposited;
        return $this;
    }

    public function getTotalWithdrawn(): string
    {
        return $this->totalWithdrawn;
    }

    public function setTotalWithdrawn(string $totalWithdrawn): static
    {
        $this->totalWithdrawn = $totalWithdrawn;
        return $this;
    }

    public function getTotalBonusEarned(): string
    {
        return $this->totalBonusEarned;
    }

    public function setTotalBonusEarned(string $totalBonusEarned): static
    {
        $this->totalBonusEarned = $totalBonusEarned;
        return $this;
    }

    public function getTotalReferralEarned(): string
    {
        return $this->totalReferralEarned;
    }

    public function setTotalReferralEarned(string $totalReferralEarned): static
    {
        $this->totalReferralEarned = $totalReferralEarned;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;
        return $this;
    }

    public function getDepositUnlockAt(): ?DateTimeImmutable
    {
        return $this->depositUnlockAt;
    }

    public function setDepositUnlockAt(?DateTimeImmutable $depositUnlockAt): static
    {
        $this->depositUnlockAt = $depositUnlockAt;
        return $this;
    }

    /**
     * @return Collection<int, Deposit>
     */
    public function getDeposits(): Collection
    {
        return $this->deposits;
    }

    /**
     * @return Collection<int, Withdrawal>
     */
    public function getWithdrawals(): Collection
    {
        return $this->withdrawals;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    /**
     * @return Collection<int, Bonus>
     */
    public function getBonuses(): Collection
    {
        return $this->bonuses;
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

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): static
    {
        $this->countryCode = $countryCode;
        return $this;
    }

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function setFailedLoginAttempts(int $failedLoginAttempts): static
    {
        $this->failedLoginAttempts = $failedLoginAttempts;
        return $this;
    }

    public function incrementFailedLoginAttempts(): static
    {
        $this->failedLoginAttempts++;
        return $this;
    }

    public function resetFailedLoginAttempts(): static
    {
        $this->failedLoginAttempts = 0;
        $this->lockedUntil = null;
        return $this;
    }

    public function getLockedUntil(): ?DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function setLockedUntil(?DateTimeImmutable $lockedUntil): static
    {
        $this->lockedUntil = $lockedUntil;
        return $this;
    }

    public function isLocked(): bool
    {
        if ($this->lockedUntil === null) {
            return false;
        }
        return $this->lockedUntil > new DateTimeImmutable();
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

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->roles, true);
    }

    public function getReferralLevel(User $referral, int $maxLevel = 2): ?int
    {
        $level = 1;
        $current = $referral;

        while ($current->getReferrer() !== null && $level <= $maxLevel) {
            if ($current->getReferrer()->getId() === $this->getId()) {
                return $level;
            }
            $current = $current->getReferrer();
            $level++;
        }

        return null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->username ?? 'User #' . $this->id;
    }
}