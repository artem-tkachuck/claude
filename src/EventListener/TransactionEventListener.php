<?php

namespace App\EventListener;

use App\Entity\Deposit;
use App\Entity\EventLog;
use App\Entity\Transaction;
use App\Entity\Withdrawal;
use App\Service\Bonus\BonusCalculator;
use App\Service\Notification\NotificationService;
use App\Service\Security\FraudDetectionService;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\EventSubscriber\EventSubscriberInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TransactionEventListener implements EventSubscriberInterface
{
    private NotificationService $notificationService;
    private FraudDetectionService $fraudDetectionService;
    private BonusCalculator $bonusCalculator;
    private LoggerInterface $logger;

    public function __construct(
        NotificationService   $notificationService,
        FraudDetectionService $fraudDetectionService,
        BonusCalculator       $bonusCalculator,
        LoggerInterface       $logger
    )
    {
        $this->notificationService = $notificationService;
        $this->fraudDetectionService = $fraudDetectionService;
        $this->bonusCalculator = $bonusCalculator;
        $this->logger = $logger;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::postPersist,
            Events::preUpdate,
            Events::postUpdate,
        ];
    }

    /**
     * Handle pre-persist event
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Deposit) {
            $this->handleDepositPrePersist($entity);
        } elseif ($entity instanceof Withdrawal) {
            $this->handleWithdrawalPrePersist($entity);
        } elseif ($entity instanceof Transaction) {
            $this->handleTransactionPrePersist($entity);
        }
    }

    /**
     * Handle deposit pre-persist
     */
    private function handleDepositPrePersist(Deposit $deposit): void
    {
        // Run fraud checks
        if (!$this->fraudDetectionService->checkDeposit($deposit)) {
            throw new RuntimeException('Deposit failed fraud detection');
        }

        // Set initial status if not set
        if (!$deposit->getStatus()) {
            $deposit->setStatus('pending');
        }

        // Log the deposit creation
        $this->logger->info('New deposit being created', [
            'user_id' => $deposit->getUser()->getId(),
            'amount' => $deposit->getAmount(),
            'tx_hash' => $deposit->getTxHash()
        ]);
    }

    /**
     * Handle withdrawal pre-persist
     */
    private function handleWithdrawalPrePersist(Withdrawal $withdrawal): void
    {
        // Run fraud checks
        if (!$this->fraudDetectionService->checkWithdrawal($withdrawal)) {
            throw new RuntimeException('Withdrawal failed fraud detection');
        }

        // Set initial status
        if (!$withdrawal->getStatus()) {
            $withdrawal->setStatus('pending');
        }

        // Set creation timestamp
        if (!$withdrawal->getCreatedAt()) {
            $withdrawal->setCreatedAt(new DateTime());
        }

        $this->logger->info('New withdrawal being created', [
            'user_id' => $withdrawal->getUser()->getId(),
            'amount' => $withdrawal->getAmount(),
            'type' => $withdrawal->getType(),
            'address' => $withdrawal->getAddress()
        ]);
    }

    /**
     * Handle transaction pre-persist
     */
    private function handleTransactionPrePersist(Transaction $transaction): void
    {
        // Set creation timestamp
        if (!$transaction->getCreatedAt()) {
            $transaction->setCreatedAt(new DateTime());
        }

        // Validate amount based on type
        if ($transaction->getType() === 'withdrawal' && $transaction->getAmount() > 0) {
            $transaction->setAmount(-abs($transaction->getAmount()));
        }
    }

    /**
     * Handle post-persist event
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Deposit) {
            $this->handleDepositPostPersist($entity);
        } elseif ($entity instanceof Withdrawal) {
            $this->handleWithdrawalPostPersist($entity);
        }
    }

    /**
     * Handle deposit post-persist
     */
    private function handleDepositPostPersist(Deposit $deposit): void
    {
        // Create event log
        $this->createEventLog($deposit->getUser(), 'transaction.deposit_created', [
            'deposit_id' => $deposit->getId(),
            'amount' => $deposit->getAmount(),
            'tx_hash' => $deposit->getTxHash()
        ]);

        // Send notifications
        if ($deposit->getAmount() >= 1000) {
            // Large deposit notification to admins
            $this->notificationService->notifyAdmins(
                sprintf(
                    "💰 Large deposit received!\n\n" .
                    "User: %s\n" .
                    "Amount: %s USDT\n" .
                    "TX: %s",
                    $deposit->getUser()->getUsername(),
                    number_format($deposit->getAmount(), 2),
                    $deposit->getTxHash()
                )
            );
        }
    }

    /**
     * Create event log
     */
    private function createEventLog($user, string $type, array $data = []): void
    {
        $event = new EventLog();
        $event->setUser($user);
        $event->setEventType($type);
        $event->setEventData($data);
        $event->setCreatedAt(new DateTime());

        // Get IP and user agent if available
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $event->setIpAddress($_SERVER['REMOTE_ADDR']);
        }
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $event->setUserAgent($_SERVER['HTTP_USER_AGENT']);
        }

        // This will be persisted when the main transaction commits
        $entityManager = $this->getEntityManager();
        $entityManager->persist($event);
    }

    /**
     * Get entity manager
     */
    private function getEntityManager()
    {
        // This would be injected in a real implementation
        return $this->entityManager;
    }

    /**
     * Handle withdrawal post-persist
     */
    private function handleWithdrawalPostPersist(Withdrawal $withdrawal): void
    {
        // Create event log
        $this->createEventLog($withdrawal->getUser(), 'transaction.withdrawal_created', [
            'withdrawal_id' => $withdrawal->getId(),
            'amount' => $withdrawal->getAmount(),
            'type' => $withdrawal->getType(),
            'address' => $withdrawal->getAddress()
        ]);

        // Notify user
        $this->notificationService->sendMessage(
            $withdrawal->getUser()->getTelegramChatId(),
            sprintf(
                "📤 Withdrawal request created:\n\n" .
                "Amount: %s USDT\n" .
                "Type: %s\n" .
                "Status: Pending approval\n\n" .
                "You will be notified once processed.",
                number_format($withdrawal->getAmount(), 2),
                ucfirst($withdrawal->getType())
            )
        );

        // Notify admins
        $this->notificationService->notifyAdminsNewWithdrawal($withdrawal);
    }

    /**
     * Handle pre-update event
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Deposit) {
            $this->handleDepositPreUpdate($entity, $args);
        } elseif ($entity instanceof Withdrawal) {
            $this->handleWithdrawalPreUpdate($entity, $args);
        }
    }

    /**
     * Handle deposit pre-update
     */
    private function handleDepositPreUpdate(Deposit $deposit, LifecycleEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($deposit);

        // Check if status changed
        if (isset($changeSet['status'])) {
            $oldStatus = $changeSet['status'][0];
            $newStatus = $changeSet['status'][1];

            $this->logger->info('Deposit status changing', [
                'deposit_id' => $deposit->getId(),
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);

            // If changing to completed, ensure confirmations are sufficient
            if ($newStatus === 'completed' && $deposit->getConfirmations() < 19) {
                throw new RuntimeException('Insufficient confirmations for deposit completion');
            }
        }
    }

    /**
     * Handle withdrawal pre-update
     */
    private function handleWithdrawalPreUpdate(Withdrawal $withdrawal, LifecycleEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($withdrawal);

        // Check if status is changing
        if (isset($changeSet['status'])) {
            $oldStatus = $changeSet['status'][0];
            $newStatus = $changeSet['status'][1];

            // Validate status transitions
            $validTransitions = [
                'pending' => ['processing', 'completed', 'failed', 'cancelled'],
                'processing' => ['completed', 'failed'],
                'completed' => [],
                'failed' => [],
                'cancelled' => []
            ];

            if (!in_array($newStatus, $validTransitions[$oldStatus])) {
                throw new RuntimeException(
                    sprintf('Invalid status transition from %s to %s', $oldStatus, $newStatus)
                );
            }

            $this->logger->info('Withdrawal status changing', [
                'withdrawal_id' => $withdrawal->getId(),
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
        }
    }

    /**
     * Handle post-update event
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Deposit) {
            $this->handleDepositPostUpdate($entity, $args);
        } elseif ($entity instanceof Withdrawal) {
            $this->handleWithdrawalPostUpdate($entity, $args);
        }
    }

    /**
     * Handle deposit post-update
     */
    private function handleDepositPostUpdate(Deposit $deposit, LifecycleEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($deposit);

        // Check if status changed to completed
        if (isset($changeSet['status']) && $changeSet['status'][1] === 'completed') {
            $this->handleDepositCompleted($deposit);
        }

        // Check if confirmations updated
        if (isset($changeSet['confirmations'])) {
            $oldConfirmations = $changeSet['confirmations'][0];
            $newConfirmations = $changeSet['confirmations'][1];

            if ($oldConfirmations < 19 && $newConfirmations >= 19) {
                // Deposit now has enough confirmations
                $this->createEventLog($deposit->getUser(), 'transaction.deposit_confirmed', [
                    'deposit_id' => $deposit->getId(),
                    'confirmations' => $newConfirmations
                ]);
            }
        }
    }

    /**
     * Handle deposit completed
     */
    private function handleDepositCompleted(Deposit $deposit): void
    {
        $user = $deposit->getUser();

        // Log event
        $this->createEventLog($user, 'transaction.deposit_completed', [
            'deposit_id' => $deposit->getId(),
            'amount' => $deposit->getAmount()
        ]);

        // Check if first deposit
        $depositCount = $user->getDeposits()->count();
        if ($depositCount === 1) {
            // First deposit - process referral bonuses
            if ($user->getReferrer()) {
                try {
                    $bonuses = $this->bonusCalculator->calculateReferralBonus($user, $deposit->getAmount());

                    $this->logger->info('Referral bonuses calculated', [
                        'user_id' => $user->getId(),
                        'deposit_amount' => $deposit->getAmount(),
                        'total_bonus' => $bonuses['total_bonus']
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to calculate referral bonuses', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Log first deposit event
            $this->createEventLog($user, 'user.first_deposit', [
                'amount' => $deposit->getAmount()
            ]);
        }

        // Check for deposit milestones
        $totalDeposits = $this->calculateUserTotalDeposits($user);
        $milestones = [1000, 5000, 10000, 50000, 100000];

        foreach ($milestones as $milestone) {
            if ($totalDeposits >= $milestone && $totalDeposits - $deposit->getAmount() < $milestone) {
                $this->createEventLog($user, 'user.deposit_milestone', [
                    'milestone' => $milestone,
                    'total_deposits' => $totalDeposits
                ]);

                // Notify user about milestone
                $this->notificationService->sendMessage(
                    $user->getTelegramChatId(),
                    sprintf(
                        "🎉 Congratulations! You've reached a deposit milestone of %s USDT!",
                        number_format($milestone, 2)
                    )
                );
            }
        }
    }

    /**
     * Calculate user total deposits
     */
    private function calculateUserTotalDeposits($user): float
    {
        $total = 0;
        foreach ($user->getDeposits() as $deposit) {
            if ($deposit->getStatus() === 'completed') {
                $total += $deposit->getAmount();
            }
        }
        return $total;
    }

    /**
     * Handle withdrawal post-update
     */
    private function handleWithdrawalPostUpdate(Withdrawal $withdrawal, LifecycleEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $changeSet = $unitOfWork->getEntityChangeSet($withdrawal);

        if (isset($changeSet['status'])) {
            $newStatus = $changeSet['status'][1];

            switch ($newStatus) {
                case 'completed':
                    $this->handleWithdrawalCompleted($withdrawal);
                    break;
                case 'failed':
                    $this->handleWithdrawalFailed($withdrawal);
                    break;
                case 'cancelled':
                    $this->handleWithdrawalCancelled($withdrawal);
                    break;
            }
        }

        // Check if approval was added
        if (isset($changeSet['approvals'])) {
            $oldApprovals = count($changeSet['approvals'][0]);
            $newApprovals = count($changeSet['approvals'][1]);

            if ($newApprovals > $oldApprovals) {
                $this->createEventLog($withdrawal->getUser(), 'transaction.withdrawal_approval_added', [
                    'withdrawal_id' => $withdrawal->getId(),
                    'approvals' => $newApprovals,
                    'required' => 2
                ]);
            }
        }
    }

    /**
     * Handle withdrawal completed
     */
    private function handleWithdrawalCompleted(Withdrawal $withdrawal): void
    {
        $user = $withdrawal->getUser();

        // Log event
        $this->createEventLog($user, 'transaction.withdrawal_completed', [
            'withdrawal_id' => $withdrawal->getId(),
            'amount' => $withdrawal->getAmount(),
            'type' => $withdrawal->getType(),
            'tx_hash' => $withdrawal->getTxHash()
        ]);

        // Notify user
        $this->notificationService->notifyWithdrawalCompleted($user, $withdrawal);

        // Update statistics
        $this->updateUserWithdrawalStats($user);
    }

    /**
     * Update user withdrawal statistics
     */
    private function updateUserWithdrawalStats($user): void
    {
        // This could update cached statistics, achievement progress, etc.
        $totalWithdrawals = 0;
        $withdrawalCount = 0;

        foreach ($user->getWithdrawals() as $withdrawal) {
            if ($withdrawal->getStatus() === 'completed') {
                $totalWithdrawals += $withdrawal->getAmount();
                $withdrawalCount++;
            }
        }

        // Store in user metadata or cache
        $metadata = $user->getMetadata() ?? [];
        $metadata['stats'] = [
            'total_withdrawals' => $totalWithdrawals,
            'withdrawal_count' => $withdrawalCount,
            'last_withdrawal' => (new DateTime())->format('c')
        ];
        $user->setMetadata($metadata);
    }

    /**
     * Handle withdrawal failed
     */
    private function handleWithdrawalFailed(Withdrawal $withdrawal): void
    {
        $user = $withdrawal->getUser();

        // Log event
        $this->createEventLog($user, 'transaction.withdrawal_failed', [
            'withdrawal_id' => $withdrawal->getId(),
            'amount' => $withdrawal->getAmount(),
            'reason' => $withdrawal->getFailureReason()
        ]);

        // Notify user
        $this->notificationService->sendMessage(
            $user->getTelegramChatId(),
            sprintf(
                "❌ Withdrawal failed:\n\n" .
                "Amount: %s USDT\n" .
                "Reason: %s\n\n" .
                "The amount has been returned to your balance.",
                number_format($withdrawal->getAmount(), 2),
                $withdrawal->getFailureReason() ?? 'Unknown error'
            )
        );

        // Notify admins about failure
        $this->notificationService->notifyAdmins(
            sprintf(
                "⚠️ Withdrawal failed!\n\n" .
                "User: %s\n" .
                "Amount: %s USDT\n" .
                "Reason: %s",
                $user->getUsername(),
                number_format($withdrawal->getAmount(), 2),
                $withdrawal->getFailureReason() ?? 'Unknown error'
            )
        );
    }

    /**
     * Handle withdrawal cancelled
     */
    private function handleWithdrawalCancelled(Withdrawal $withdrawal): void
    {
        $user = $withdrawal->getUser();

        // Log event
        $this->createEventLog($user, 'transaction.withdrawal_cancelled', [
            'withdrawal_id' => $withdrawal->getId(),
            'amount' => $withdrawal->getAmount(),
            'reason' => $withdrawal->getFailureReason()
        ]);

        // Notify user
        $this->notificationService->notifyWithdrawalCancelled($user, $withdrawal);
    }
}