<?php

namespace App\Service\Bonus;

use App\Entity\Bonus;
use App\Entity\User;
use App\Repository\BonusRepository;
use App\Repository\DepositRepository;
use App\Repository\SystemSettingsRepository;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

class BonusCalculator
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private DepositRepository $depositRepository;
    private BonusRepository $bonusRepository;
    private SystemSettingsRepository $settingsRepository;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface   $entityManager,
        UserRepository           $userRepository,
        DepositRepository        $depositRepository,
        BonusRepository          $bonusRepository,
        SystemSettingsRepository $settingsRepository,
        NotificationService      $notificationService,
        LoggerInterface          $logger
    )
    {
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->depositRepository = $depositRepository;
        $this->bonusRepository = $bonusRepository;
        $this->settingsRepository = $settingsRepository;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    /**
     * Calculate and distribute daily bonuses
     */
    public function calculateDailyBonuses(float $totalProfit): array
    {
        $this->logger->info('Starting daily bonus calculation', [
            'total_profit' => $totalProfit
        ]);

        $bonusConfig = $this->settingsRepository->getBonusConfig();

        // Calculate distributable amount
        $companyProfitPercent = $bonusConfig['company_profit_percent'] ?? 30;
        $distributionPercent = 100 - $companyProfitPercent;
        $distributionAmount = ($totalProfit * $distributionPercent) / 100;
        $companyProfit = ($totalProfit * $companyProfitPercent) / 100;

        // Get all active users with deposits
        $activeUsers = $this->userRepository->findActiveUsersWithDeposits();

        if (empty($activeUsers)) {
            $this->logger->warning('No active users with deposits found');
            return [
                'distributed' => 0,
                'company_profit' => $totalProfit,
                'users_count' => 0
            ];
        }

        // Calculate total deposits
        $totalDeposits = 0;
        $userDeposits = [];

        foreach ($activeUsers as $user) {
            $userDepositAmount = $this->depositRepository->getUserActiveDepositsSum($user);
            if ($userDepositAmount > 0) {
                $totalDeposits += $userDepositAmount;
                $userDeposits[$user->getId()] = [
                    'user' => $user,
                    'amount' => $userDepositAmount
                ];
            }
        }

        if ($totalDeposits == 0) {
            $this->logger->warning('Total deposits is zero');
            return [
                'distributed' => 0,
                'company_profit' => $totalProfit,
                'users_count' => 0
            ];
        }

        // Start transaction
        $this->entityManager->beginTransaction();

        try {
            $distributedCount = 0;
            $totalDistributed = 0;

            foreach ($userDeposits as $userId => $data) {
                $user = $data['user'];
                $userDepositAmount = $data['amount'];

                // Calculate user's share
                $userShare = ($userDepositAmount / $totalDeposits) * $distributionAmount;

                if ($userShare < 0.01) {
                    // Skip amounts less than 1 cent
                    continue;
                }

                // Create bonus record
                $bonus = new Bonus();
                $bonus->setUser($user);
                $bonus->setType('daily_profit');
                $bonus->setAmount($userShare);
                $bonus->setDescription('Daily profit distribution');
                $bonus->setMetadata([
                    'total_profit' => $totalProfit,
                    'distribution_amount' => $distributionAmount,
                    'user_deposit' => $userDepositAmount,
                    'total_deposits' => $totalDeposits,
                    'percentage' => ($userDepositAmount / $totalDeposits) * 100
                ]);
                $bonus->setStatus('completed');
                $bonus->setCreatedAt(new DateTime());

                $this->entityManager->persist($bonus);

                // Update user balance
                $user->addBonusBalance($userShare);
                $this->entityManager->persist($user);

                $distributedCount++;
                $totalDistributed += $userShare;

                // Send notification
                $this->notificationService->notifyUserBonus($user, $userShare, 'daily_profit');

                $this->logger->info('Bonus calculated for user', [
                    'user_id' => $user->getId(),
                    'amount' => $userShare,
                    'deposit_amount' => $userDepositAmount
                ]);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('Daily bonuses distributed successfully', [
                'total_distributed' => $totalDistributed,
                'company_profit' => $companyProfit,
                'users_count' => $distributedCount
            ]);

            return [
                'distributed' => $totalDistributed,
                'company_profit' => $companyProfit,
                'users_count' => $distributedCount,
                'details' => [
                    'total_profit' => $totalProfit,
                    'distribution_percent' => $distributionPercent,
                    'total_deposits' => $totalDeposits
                ]
            ];

        } catch (Exception $e) {
            $this->entityManager->rollback();

            $this->logger->error('Failed to distribute bonuses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Calculate referral bonus
     */
    public function calculateReferralBonus(User $referral, float $depositAmount): array
    {
        $referralConfig = $this->settingsRepository->getReferralConfig();

        if (!$referralConfig['enabled']) {
            return ['total_bonus' => 0, 'bonuses' => []];
        }

        $bonuses = [];
        $totalBonus = 0;

        // Level 1 - Direct referrer
        if ($referral->getReferrer()) {
            $level1Percent = $referralConfig['level_1_percent'] ?? 10;
            $level1Bonus = ($depositAmount * $level1Percent) / 100;

            if ($level1Bonus > 0) {
                $bonus = $this->createReferralBonus(
                    $referral->getReferrer(),
                    $referral,
                    $level1Bonus,
                    1,
                    $depositAmount
                );

                $bonuses[] = $bonus;
                $totalBonus += $level1Bonus;
            }

            // Level 2 - Referrer's referrer
            if ($referralConfig['levels'] >= 2 && $referral->getReferrer()->getReferrer()) {
                $level2Percent = $referralConfig['level_2_percent'] ?? 5;
                $level2Bonus = ($depositAmount * $level2Percent) / 100;

                if ($level2Bonus > 0) {
                    $bonus = $this->createReferralBonus(
                        $referral->getReferrer()->getReferrer(),
                        $referral,
                        $level2Bonus,
                        2,
                        $depositAmount
                    );

                    $bonuses[] = $bonus;
                    $totalBonus += $level2Bonus;
                }
            }
        }

        return [
            'total_bonus' => $totalBonus,
            'bonuses' => $bonuses
        ];
    }

    /**
     * Create referral bonus
     */
    private function createReferralBonus(
        User  $recipient,
        User  $referral,
        float $amount,
        int   $level,
        float $depositAmount
    ): Bonus
    {
        $bonus = new Bonus();
        $bonus->setUser($recipient);
        $bonus->setType('referral');
        $bonus->setAmount($amount);
        $bonus->setDescription("Level {$level} referral bonus from {$referral->getUsername()}");
        $bonus->setMetadata([
            'referral_id' => $referral->getId(),
            'referral_username' => $referral->getUsername(),
            'level' => $level,
            'deposit_amount' => $depositAmount
        ]);
        $bonus->setStatus('completed');
        $bonus->setCreatedAt(new DateTime());

        $this->entityManager->persist($bonus);

        // Update user balance
        $recipient->addBonusBalance($amount);
        $this->entityManager->persist($recipient);

        // Send notification
        $this->notificationService->notifyUserBonus($recipient, $amount, 'referral');

        $this->logger->info('Referral bonus created', [
            'recipient_id' => $recipient->getId(),
            'referral_id' => $referral->getId(),
            'level' => $level,
            'amount' => $amount
        ]);

        return $bonus;
    }

    /**
     * Get bonus statistics
     */
    public function getBonusStatistics(DateTime $from, DateTime $to): array
    {
        $stats = $this->bonusRepository->getBonusStatistics($from, $to);

        return [
            'total_distributed' => $stats['total_amount'] ?? 0,
            'total_count' => $stats['total_count'] ?? 0,
            'by_type' => $stats['by_type'] ?? [],
            'daily_average' => $this->calculateDailyAverage($stats['by_date'] ?? []),
            'top_recipients' => $this->getTopRecipients($from, $to, 10)
        ];
    }

    /**
     * Calculate daily average
     */
    private function calculateDailyAverage(array $dailyData): float
    {
        if (empty($dailyData)) {
            return 0;
        }

        $total = array_sum(array_column($dailyData, 'amount'));
        return $total / count($dailyData);
    }

    /**
     * Get top bonus recipients
     */
    private function getTopRecipients(DateTime $from, DateTime $to, int $limit): array
    {
        return $this->bonusRepository->getTopRecipients($from, $to, $limit);
    }

    /**
     * Process automatic withdrawals
     */
    public function processAutomaticWithdrawals(): array
    {
        $usersWithAutoWithdraw = $this->userRepository->findUsersWithAutoWithdrawal();
        $processed = 0;
        $totalAmount = 0;

        foreach ($usersWithAutoWithdraw as $user) {
            if ($user->getBonusBalance() >= $user->getAutoWithdrawMinAmount()) {
                try {
                    // Create withdrawal request
                    $amount = $user->getBonusBalance();

                    // Process withdrawal (implement in WithdrawalService)
                    // $this->withdrawalService->createAutomaticWithdrawal($user, $amount);

                    $processed++;
                    $totalAmount += $amount;

                    $this->logger->info('Automatic withdrawal processed', [
                        'user_id' => $user->getId(),
                        'amount' => $amount
                    ]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to process automatic withdrawal', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return [
            'processed' => $processed,
            'total_amount' => $totalAmount
        ];
    }

    /**
     * Recalculate user bonus balance
     */
    public function recalculateUserBonusBalance(User $user): float
    {
        $totalBonuses = $this->bonusRepository->getUserTotalBonuses($user);
        $totalWithdrawals = $this->bonusRepository->getUserTotalWithdrawals($user);

        $balance = $totalBonuses - $totalWithdrawals;

        $user->setBonusBalance($balance);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->logger->info('User bonus balance recalculated', [
            'user_id' => $user->getId(),
            'total_bonuses' => $totalBonuses,
            'total_withdrawals' => $totalWithdrawals,
            'balance' => $balance
        ]);

        return $balance;
    }
}