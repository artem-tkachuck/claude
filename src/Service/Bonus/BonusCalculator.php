<?php

namespace App\Service\Bonus;

use App\Entity\Bonus;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\BonusRepository;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

class BonusCalculator
{
    private EntityManagerInterface $em;
    private UserRepository $userRepository;
    private BonusRepository $bonusRepository;
    private NotificationService $notificationService;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        EntityManagerInterface $em,
        UserRepository         $userRepository,
        BonusRepository        $bonusRepository,
        NotificationService    $notificationService,
        LoggerInterface        $logger,
        array                  $bonusConfig
    )
    {
        $this->em = $em;
        $this->userRepository = $userRepository;
        $this->bonusRepository = $bonusRepository;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->config = $bonusConfig;
    }

    public function calculateDailyBonuses(string $profitAmount): array
    {
        $this->logger->info('Starting daily bonus calculation', ['profit' => $profitAmount]);

        try {
            $this->em->beginTransaction();

            // Get total deposits
            $totalDeposits = $this->userRepository->getTotalDeposits();

            if (bccomp($totalDeposits, '0', 8) <= 0) {
                $this->logger->warning('No deposits found for bonus distribution');
                $this->em->commit();
                return [];
            }

            // Calculate distribution amount (profit * distribution percentage)
            $distributionPercentage = $this->config['distribution_percentage'] / 100;
            $distributionAmount = bcmul($profitAmount, (string)$distributionPercentage, 8);

            // Get all active users with deposits
            $users = $this->userRepository->findUsersWithDeposits();
            $bonuses = [];

            foreach ($users as $user) {
                if (bccomp($user->getDepositBalance(), '0', 8) <= 0) {
                    continue;
                }

                // Calculate user's share
                $userShare = bcdiv($user->getDepositBalance(), $totalDeposits, 10);
                $bonusAmount = bcmul($distributionAmount, $userShare, 8);

                if (bccomp($bonusAmount, '0', 8) > 0) {
                    $bonus = $this->createBonus($user, $bonusAmount, $profitAmount);
                    $bonuses[] = $bonus;

                    // Update user balance
                    $newBalance = bcadd($user->getBonusBalance(), $bonusAmount, 8);
                    $user->setBonusBalance($newBalance);

                    // Create transaction record
                    $this->createBonusTransaction($user, $bonus);
                }
            }

            $this->em->flush();
            $this->em->commit();

            // Send notifications
            $this->notifyUsers($bonuses);

            $this->logger->info('Daily bonus calculation completed', [
                'users_count' => count($bonuses),
                'total_distributed' => $distributionAmount,
            ]);

            return $bonuses;
        } catch (Exception $e) {
            $this->em->rollback();
            $this->logger->error('Bonus calculation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function createBonus(User $user, string $amount, string $totalProfit): Bonus
    {
        $bonus = new Bonus();
        $bonus->setUser($user);
        $bonus->setAmount($amount);
        $bonus->setTotalProfit($totalProfit);
        $bonus->setDepositBalance($user->getDepositBalance());
        $bonus->setCalculatedAt(new DateTimeImmutable());

        $this->em->persist($bonus);

        return $bonus;
    }

    private function createBonusTransaction(User $user, Bonus $bonus): void
    {
        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setType(Transaction::TYPE_BONUS);
        $transaction->setAmount($bonus->getAmount());
        $transaction->setCurrency('USDT');
        $transaction->setStatus(Transaction::STATUS_COMPLETED);
        $transaction->setDescription('Daily bonus distribution');
        $transaction->setMetadata([
            'bonus_id' => $bonus->getId(),
            'deposit_balance' => $bonus->getDepositBalance(),
            'total_profit' => $bonus->getTotalProfit(),
        ]);

        $this->em->persist($transaction);
    }

    private function notifyUsers(array $bonuses): void
    {
        foreach ($bonuses as $bonus) {
            try {
                $this->notificationService->notifyBonusReceived(
                    $bonus->getUser(),
                    $bonus->getAmount()
                );
            } catch (Exception $e) {
                $this->logger->error('Failed to send bonus notification', [
                    'user_id' => $bonus->getUser()->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function calculateReferralBonus(User $referral, string $depositAmount): array
    {
        $bonuses = [];
        $currentUser = $referral;
        $level = 1;

        while ($currentUser->getReferrer() !== null && $level <= $this->config['referral']['max_levels']) {
            $referrer = $currentUser->getReferrer();
            $percentage = $this->config['referral']['level_' . $level] ?? 0;

            if ($percentage > 0) {
                $bonusAmount = bcmul($depositAmount, (string)($percentage / 100), 8);

                if (bccomp($bonusAmount, '0', 8) > 0) {
                    // Update referrer balance
                    $newBalance = bcadd($referrer->getReferralBalance(), $bonusAmount, 8);
                    $referrer->setReferralBalance($newBalance);

                    // Create referral bonus record
                    $this->createReferralBonus($referrer, $referral, $bonusAmount, $level);

                    $bonuses[] = [
                        'referrer' => $referrer,
                        'amount' => $bonusAmount,
                        'level' => $level,
                    ];
                }
            }

            $currentUser = $referrer;
            $level++;
        }

        return $bonuses;
    }

    private function createReferralBonus(User $referrer, User $referral, string $amount, int $level): void
    {
        $transaction = new Transaction();
        $transaction->setUser($referrer);
        $transaction->setType(Transaction::TYPE_REFERRAL_BONUS);
        $transaction->setAmount($amount);
        $transaction->setCurrency('USDT');
        $transaction->setStatus(Transaction::STATUS_COMPLETED);
        $transaction->setDescription(sprintf('Level %d referral bonus from %s', $level, $referral->getUsername()));
        $transaction->setMetadata([
            'referral_id' => $referral->getId(),
            'referral_username' => $referral->getUsername(),
            'level' => $level,
        ]);

        $this->em->persist($transaction);
    }
}