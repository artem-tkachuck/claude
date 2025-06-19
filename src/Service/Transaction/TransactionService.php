<?php

namespace App\Service\Transaction;

use App\Entity\Deposit;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\Withdrawal;
use App\Repository\TransactionRepository;
use App\Service\Blockchain\TronService;
use App\Service\Notification\NotificationService;
use App\Service\Security\EncryptionService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;

class TransactionService
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;
    private TronService $tronService;
    private NotificationService $notificationService;
    private EncryptionService $encryptionService;
    private LockFactory $lockFactory;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        TransactionRepository  $transactionRepository,
        TronService            $tronService,
        NotificationService    $notificationService,
        EncryptionService      $encryptionService,
        LockFactory            $lockFactory,
        LoggerInterface        $logger
    )
    {
        $this->entityManager = $entityManager;
        $this->transactionRepository = $transactionRepository;
        $this->tronService = $tronService;
        $this->notificationService = $notificationService;
        $this->encryptionService = $encryptionService;
        $this->lockFactory = $lockFactory;
        $this->logger = $logger;
    }

    /**
     * Create deposit transaction
     */
    public function createDeposit(User $user, array $txData): Deposit
    {
        // Prevent duplicate processing
        $lock = $this->lockFactory->createLock('deposit_' . $txData['txid']);

        if (!$lock->acquire()) {
            throw new RuntimeException('Transaction already being processed');
        }

        try {
            // Check if transaction already exists
            $existing = $this->transactionRepository->findOneBy(['txHash' => $txData['txid']]);
            if ($existing) {
                throw new RuntimeException('Transaction already processed');
            }

            $deposit = new Deposit();
            $deposit->setUser($user);
            $deposit->setAmount($txData['amount']);
            $deposit->setTxHash($txData['txid']);
            $deposit->setFromAddress($txData['from']);
            $deposit->setToAddress($txData['to']);
            $deposit->setConfirmations($txData['confirmations']);
            $deposit->setStatus('pending');
            $deposit->setCreatedAt(new DateTime());

            // Create transaction record
            $transaction = new Transaction();
            $transaction->setUser($user);
            $transaction->setType('deposit');
            $transaction->setAmount($txData['amount']);
            $transaction->setStatus('pending');
            $transaction->setTxHash($txData['txid']);
            $transaction->setMetadata([
                'from' => $txData['from'],
                'to' => $txData['to'],
                'confirmations' => $txData['confirmations'],
                'timestamp' => $txData['timestamp']
            ]);
            $transaction->setCreatedAt(new DateTime());

            $this->entityManager->persist($deposit);
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            // Check confirmations
            $this->checkDepositConfirmations($deposit);

            $this->logger->info('Deposit created', [
                'user_id' => $user->getId(),
                'amount' => $txData['amount'],
                'txid' => $txData['txid']
            ]);

            return $deposit;
        } finally {
            $lock->release();
        }
    }

    /**
     * Check deposit confirmations
     */
    public function checkDepositConfirmations(Deposit $deposit): void
    {
        $requiredConfirmations = 19; // TRC20 standard

        if ($deposit->getConfirmations() >= $requiredConfirmations && $deposit->getStatus() === 'pending') {
            $this->confirmDeposit($deposit);
        }
    }

    /**
     * Confirm deposit
     */
    private function confirmDeposit(Deposit $deposit): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Update deposit status
            $deposit->setStatus('completed');
            $deposit->setConfirmedAt(new DateTime());

            // Update user balance
            $user = $deposit->getUser();
            $user->addDepositBalance($deposit->getAmount());

            // Update transaction status
            $transaction = $this->transactionRepository->findOneBy([
                'txHash' => $deposit->getTxHash()
            ]);
            if ($transaction) {
                $transaction->setStatus('completed');
            }

            $this->entityManager->persist($deposit);
            $this->entityManager->persist($user);
            if ($transaction) {
                $this->entityManager->persist($transaction);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Send notifications
            $this->notificationService->notifyDepositConfirmed($user, $deposit);
            $this->notificationService->notifyAdminsNewDeposit($deposit);

            // Process referral bonuses if first deposit
            if ($this->isFirstDeposit($user, $deposit)) {
                $this->processReferralBonuses($user, $deposit->getAmount());
            }

            $this->logger->info('Deposit confirmed', [
                'deposit_id' => $deposit->getId(),
                'user_id' => $user->getId(),
                'amount' => $deposit->getAmount()
            ]);

        } catch (Exception $e) {
            $this->entityManager->rollback();

            $this->logger->error('Failed to confirm deposit', [
                'deposit_id' => $deposit->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Check if this is user's first deposit
     */
    private function isFirstDeposit(User $user, Deposit $currentDeposit): bool
    {
        $depositCount = $this->entityManager->getRepository(Deposit::class)
            ->count([
                'user' => $user,
                'status' => 'completed'
            ]);

        return $depositCount === 1;
    }

    /**
     * Process referral bonuses
     */
    private function processReferralBonuses(User $user, float $depositAmount): void
    {
        // This will be handled by BonusCalculator service
        // Implemented here for reference
        if ($user->getReferrer()) {
            $this->logger->info('Processing referral bonuses', [
                'user_id' => $user->getId(),
                'referrer_id' => $user->getReferrer()->getId(),
                'deposit_amount' => $depositAmount
            ]);
        }
    }

    /**
     * Create withdrawal
     */
    public function createWithdrawal(User $user, float $amount, string $address, string $type = 'bonus'): Withdrawal
    {
        // Validate address
        if (!$this->tronService->validateAddress($address)) {
            throw new InvalidArgumentException('Invalid withdrawal address');
        }

        // Check balance
        $availableBalance = $type === 'bonus' ? $user->getBonusBalance() : $user->getDepositBalance();
        if ($amount > $availableBalance) {
            throw new RuntimeException('Insufficient balance');
        }

        // Check withdrawal restrictions
        $this->checkWithdrawalRestrictions($user, $amount, $type);

        $withdrawal = new Withdrawal();
        $withdrawal->setUser($user);
        $withdrawal->setAmount($amount);
        $withdrawal->setAddress($address);
        $withdrawal->setType($type);
        $withdrawal->setStatus('pending');
        $withdrawal->setCreatedAt(new DateTime());

        // Create transaction record
        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setType('withdrawal');
        $transaction->setAmount(-$amount); // Negative for withdrawal
        $transaction->setStatus('pending');
        $transaction->setMetadata([
            'withdrawal_type' => $type,
            'address' => $address
        ]);
        $transaction->setCreatedAt(new DateTime());

        // Deduct balance immediately
        if ($type === 'bonus') {
            $user->deductBonusBalance($amount);
        } else {
            $user->deductDepositBalance($amount);
        }

        $this->entityManager->persist($withdrawal);
        $this->entityManager->persist($transaction);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Notify admins
        $this->notificationService->notifyAdminsNewWithdrawal($withdrawal);

        $this->logger->info('Withdrawal created', [
            'withdrawal_id' => $withdrawal->getId(),
            'user_id' => $user->getId(),
            'amount' => $amount,
            'type' => $type
        ]);

        return $withdrawal;
    }

    /**
     * Check withdrawal restrictions
     */
    private function checkWithdrawalRestrictions(User $user, float $amount, string $type): void
    {
        // Check minimum withdrawal amount
        if ($amount < 10) {
            throw new RuntimeException('Minimum withdrawal amount is 10 USDT');
        }

        // Check deposit withdrawal restrictions (1 year lock)
        if ($type === 'deposit') {
            $firstDeposit = $this->entityManager->getRepository(Deposit::class)
                ->findOneBy(['user' => $user], ['createdAt' => 'ASC']);

            if ($firstDeposit) {
                $oneYearAgo = new DateTime('-1 year');
                if ($firstDeposit->getCreatedAt() > $oneYearAgo) {
                    throw new RuntimeException('Deposits can only be withdrawn after 1 year');
                }
            }
        }

        // Check daily withdrawal limits
        $dailyWithdrawn = $this->transactionRepository->getUserDailyWithdrawalSum($user);
        if ($dailyWithdrawn + $amount > 10000) {
            throw new RuntimeException('Daily withdrawal limit exceeded');
        }

        // Check for pending withdrawals
        $pendingCount = $this->entityManager->getRepository(Withdrawal::class)
            ->count(['user' => $user, 'status' => 'pending']);
        if ($pendingCount > 0) {
            throw new RuntimeException('You have pending withdrawals');
        }
    }

    /**
     * Process withdrawal
     */
    public function processWithdrawal(Withdrawal $withdrawal, User $admin): void
    {
        if ($withdrawal->getStatus() !== 'pending') {
            throw new RuntimeException('Withdrawal is not pending');
        }

        // Add admin approval
        $withdrawal->addApproval($admin);

        // Check if we have enough approvals (2 required)
        if (count($withdrawal->getApprovals()) < 2) {
            $this->entityManager->persist($withdrawal);
            $this->entityManager->flush();

            $this->logger->info('Withdrawal approval added', [
                'withdrawal_id' => $withdrawal->getId(),
                'admin_id' => $admin->getId(),
                'approvals' => count($withdrawal->getApprovals())
            ]);

            return;
        }

        // Process the withdrawal
        $this->executeWithdrawal($withdrawal);
    }

    /**
     * Execute withdrawal
     */
    private function executeWithdrawal(Withdrawal $withdrawal): void
    {
        $lock = $this->lockFactory->createLock('withdrawal_' . $withdrawal->getId());

        if (!$lock->acquire()) {
            throw new RuntimeException('Withdrawal already being processed');
        }

        try {
            $this->entityManager->beginTransaction();

            // Get hot wallet details
            $hotWalletAddress = $_ENV['HOT_WALLET_ADDRESS'];
            $hotWalletKey = $_ENV['HOT_WALLET_PRIVATE_KEY'];

            // Send transaction
            $result = $this->tronService->sendUsdt(
                $hotWalletAddress,
                $withdrawal->getAddress(),
                $withdrawal->getAmount(),
                $hotWalletKey
            );

            // Update withdrawal
            $withdrawal->setStatus('completed');
            $withdrawal->setTxHash($result['txid']);
            $withdrawal->setProcessedAt(new DateTime());

            // Update transaction
            $transaction = $this->transactionRepository->findOneBy([
                'user' => $withdrawal->getUser(),
                'type' => 'withdrawal',
                'status' => 'pending',
                'amount' => -$withdrawal->getAmount()
            ]);

            if ($transaction) {
                $transaction->setStatus('completed');
                $transaction->setTxHash($result['txid']);
            }

            $this->entityManager->persist($withdrawal);
            if ($transaction) {
                $this->entityManager->persist($transaction);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Send notifications
            $this->notificationService->notifyWithdrawalCompleted($withdrawal->getUser(), $withdrawal);

            $this->logger->info('Withdrawal executed', [
                'withdrawal_id' => $withdrawal->getId(),
                'txid' => $result['txid']
            ]);

        } catch (Exception $e) {
            $this->entityManager->rollback();

            // Refund balance on failure
            $user = $withdrawal->getUser();
            if ($withdrawal->getType() === 'bonus') {
                $user->addBonusBalance($withdrawal->getAmount());
            } else {
                $user->addDepositBalance($withdrawal->getAmount());
            }

            $withdrawal->setStatus('failed');
            $withdrawal->setFailureReason($e->getMessage());

            $this->entityManager->persist($user);
            $this->entityManager->persist($withdrawal);
            $this->entityManager->flush();

            $this->logger->error('Withdrawal execution failed', [
                'withdrawal_id' => $withdrawal->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Get transaction history
     */
    public function getUserTransactionHistory(User $user, int $limit = 50): array
    {
        return $this->transactionRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            $limit
        );
    }

    /**
     * Cancel withdrawal
     */
    public function cancelWithdrawal(Withdrawal $withdrawal, string $reason): void
    {
        if ($withdrawal->getStatus() !== 'pending') {
            throw new RuntimeException('Only pending withdrawals can be cancelled');
        }

        // Refund balance
        $user = $withdrawal->getUser();
        if ($withdrawal->getType() === 'bonus') {
            $user->addBonusBalance($withdrawal->getAmount());
        } else {
            $user->addDepositBalance($withdrawal->getAmount());
        }

        $withdrawal->setStatus('cancelled');
        $withdrawal->setFailureReason($reason);

        $this->entityManager->persist($user);
        $this->entityManager->persist($withdrawal);
        $this->entityManager->flush();

        $this->notificationService->notifyWithdrawalCancelled($user, $withdrawal);

        $this->logger->info('Withdrawal cancelled', [
            'withdrawal_id' => $withdrawal->getId(),
            'reason' => $reason
        ]);
    }
}