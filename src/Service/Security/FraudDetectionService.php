<?php

namespace App\Service\Security;

use App\Entity\Deposit;
use App\Entity\EventLog;
use App\Entity\User;
use App\Entity\Withdrawal;
use App\Repository\DepositRepository;
use App\Repository\EventLogRepository;
use App\Repository\UserRepository;
use App\Repository\WithdrawalRepository;
use App\Service\Notification\NotificationService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FraudDetectionService
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private DepositRepository $depositRepository;
    private WithdrawalRepository $withdrawalRepository;
    private EventLogRepository $eventLogRepository;
    private NotificationService $notificationService;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        EntityManagerInterface                 $entityManager,
        UserRepository                         $userRepository,
        DepositRepository                      $depositRepository,
        WithdrawalRepository                   $withdrawalRepository,
        EventLogRepository                     $eventLogRepository,
        NotificationService                    $notificationService,
        LoggerInterface                        $logger,
        #[Autowire('%fraud_detection%')] array $config
    )
    {
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->depositRepository = $depositRepository;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->eventLogRepository = $eventLogRepository;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Check user for suspicious activity
     */
    public function checkUser(User $user): array
    {
        $suspiciousActivities = [];
        $riskScore = 0;

        // Check rapid account creation
        if ($this->checkRapidAccountCreation($user)) {
            $suspiciousActivities[] = 'rapid_account_creation';
            $riskScore += 20;
        }

        // Check multiple accounts from same IP
        if ($this->checkMultipleAccountsSameIP($user)) {
            $suspiciousActivities[] = 'multiple_accounts_same_ip';
            $riskScore += 30;
        }

        // Check suspicious deposit patterns
        $depositIssues = $this->checkDepositPatterns($user);
        if (!empty($depositIssues)) {
            $suspiciousActivities = array_merge($suspiciousActivities, $depositIssues);
            $riskScore += count($depositIssues) * 15;
        }

        // Check withdrawal patterns
        $withdrawalIssues = $this->checkWithdrawalPatterns($user);
        if (!empty($withdrawalIssues)) {
            $suspiciousActivities = array_merge($suspiciousActivities, $withdrawalIssues);
            $riskScore += count($withdrawalIssues) * 20;
        }

        // Check login patterns
        if ($this->checkSuspiciousLoginPatterns($user)) {
            $suspiciousActivities[] = 'suspicious_login_pattern';
            $riskScore += 25;
        }

        // Check referral abuse
        if ($this->checkReferralAbuse($user)) {
            $suspiciousActivities[] = 'referral_abuse';
            $riskScore += 35;
        }

        // Log if suspicious
        if ($riskScore > 0) {
            $this->logSuspiciousActivity($user, $suspiciousActivities, $riskScore);
        }

        return [
            'risk_score' => min($riskScore, 100),
            'activities' => $suspiciousActivities,
            'is_high_risk' => $riskScore >= $this->config['high_risk_threshold'],
            'should_block' => $riskScore >= $this->config['block_threshold']
        ];
    }

    /**
     * Check rapid account creation
     */
    private function checkRapidAccountCreation(User $user): bool
    {
        $recentUsers = $this->userRepository->findRecentUsersByIP(
            $user->getRegistrationIp(),
            new DateTime('-1 hour'),
            5
        );

        return count($recentUsers) > 2;
    }

    /**
     * Check multiple accounts from same IP
     */
    private function checkMultipleAccountsSameIP(User $user): bool
    {
        if (!$user->getRegistrationIp()) {
            return false;
        }

        $accounts = $this->userRepository->findByRegistrationIp($user->getRegistrationIp());

        if (count($accounts) > $this->config['max_accounts_per_ip']) {
            return true;
        }

        // Check if accounts have similar patterns
        $similarPatterns = 0;
        foreach ($accounts as $account) {
            if ($account->getId() === $user->getId()) {
                continue;
            }

            // Similar username pattern
            if ($this->haveSimilarUsernames($user->getUsername(), $account->getUsername())) {
                $similarPatterns++;
            }

            // Same referrer
            if ($user->getReferrer() && $user->getReferrer() === $account->getReferrer()) {
                $similarPatterns++;
            }
        }

        return $similarPatterns >= 2;
    }

    /**
     * Check if usernames are similar
     */
    private function haveSimilarUsernames(string $username1, string $username2): bool
    {
        // Remove numbers and check similarity
        $base1 = preg_replace('/\d+/', '', $username1);
        $base2 = preg_replace('/\d+/', '', $username2);

        if ($base1 === $base2 && $base1 !== '') {
            return true;
        }

        // Check Levenshtein distance
        $distance = levenshtein($username1, $username2);
        $maxLength = max(strlen($username1), strlen($username2));

        return $distance / $maxLength < 0.3;
    }

    /**
     * Check deposit patterns
     */
    private function checkDepositPatterns(User $user): array
    {
        $issues = [];

        // Get recent deposits
        $recentDeposits = $this->depositRepository->findRecentByUser($user, 30);

        if (count($recentDeposits) < 2) {
            return $issues;
        }

        // Check for structuring (splitting large amounts)
        if ($this->detectStructuring($recentDeposits)) {
            $issues[] = 'deposit_structuring';
        }

        // Check for round-trip transactions
        if ($this->detectRoundTrip($user)) {
            $issues[] = 'round_trip_transactions';
        }

        // Check deposit frequency
        $depositsLast24h = $this->depositRepository->countUserDepositsInPeriod(
            $user,
            new DateTime('-24 hours')
        );

        if ($depositsLast24h > $this->config['max_deposits_per_day']) {
            $issues[] = 'excessive_deposit_frequency';
        }

        return $issues;
    }

    /**
     * Detect structuring
     */
    private function detectStructuring(array $deposits): bool
    {
        if (count($deposits) < 3) {
            return false;
        }

        $amounts = array_map(fn($d) => $d->getAmount(), $deposits);
        $times = array_map(fn($d) => $d->getCreatedAt()->getTimestamp(), $deposits);

        // Check for similar amounts
        $avgAmount = array_sum($amounts) / count($amounts);
        $similarAmounts = 0;

        foreach ($amounts as $amount) {
            if (abs($amount - $avgAmount) / $avgAmount < 0.1) {
                $similarAmounts++;
            }
        }

        // Check for regular intervals
        $intervals = [];
        for ($i = 1; $i < count($times); $i++) {
            $intervals[] = $times[$i] - $times[$i - 1];
        }

        $avgInterval = array_sum($intervals) / count($intervals);
        $regularIntervals = 0;

        foreach ($intervals as $interval) {
            if (abs($interval - $avgInterval) / $avgInterval < 0.2) {
                $regularIntervals++;
            }
        }

        return $similarAmounts >= count($amounts) * 0.7 &&
            $regularIntervals >= count($intervals) * 0.6;
    }

    /**
     * Detect round-trip transactions
     */
    private function detectRoundTrip(User $user): bool
    {
        $recentDeposits = $this->depositRepository->findRecentByUser($user, 7);
        $recentWithdrawals = $this->withdrawalRepository->findRecentByUser($user, 7);

        if (empty($recentDeposits) || empty($recentWithdrawals)) {
            return false;
        }

        foreach ($recentDeposits as $deposit) {
            foreach ($recentWithdrawals as $withdrawal) {
                // Check if withdrawal happened shortly after deposit with similar amount
                $timeDiff = $withdrawal->getCreatedAt()->getTimestamp() -
                    $deposit->getCreatedAt()->getTimestamp();

                if ($timeDiff > 0 && $timeDiff < 86400) { // Within 24 hours
                    $amountDiff = abs($deposit->getAmount() - $withdrawal->getAmount());

                    if ($amountDiff / $deposit->getAmount() < 0.05) { // Within 5%
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check withdrawal patterns
     */
    private function checkWithdrawalPatterns(User $user): array
    {
        $issues = [];

        // Check withdrawal to deposit ratio
        $totalDeposits = $this->depositRepository->getUserTotalDeposits($user);
        $totalWithdrawals = $this->withdrawalRepository->getUserTotalWithdrawals($user);

        if ($totalDeposits > 0) {
            $ratio = $totalWithdrawals / $totalDeposits;

            if ($ratio > $this->config['suspicious_withdrawal_ratio']) {
                $issues[] = 'high_withdrawal_ratio';
            }
        }

        // Check for multiple withdrawal addresses
        $uniqueAddresses = $this->withdrawalRepository->getUniqueWithdrawalAddresses($user);

        if (count($uniqueAddresses) > $this->config['max_withdrawal_addresses']) {
            $issues[] = 'multiple_withdrawal_addresses';
        }

        // Check withdrawal timing patterns
        if ($this->hasAutomatedWithdrawalPattern($user)) {
            $issues[] = 'automated_withdrawal_pattern';
        }

        return $issues;
    }

    /**
     * Check for automated withdrawal pattern
     */
    private function hasAutomatedWithdrawalPattern(User $user): bool
    {
        $withdrawals = $this->withdrawalRepository->findRecentByUser($user, 30);

        if (count($withdrawals) < 5) {
            return false;
        }

        // Check for consistent time patterns
        $hours = array_map(fn($w) => (int)$w->getCreatedAt()->format('H'), $withdrawals);
        $hourCounts = array_count_values($hours);

        // If most withdrawals happen at the same hour
        $maxCount = max($hourCounts);

        return $maxCount >= count($withdrawals) * 0.6;
    }

    /**
     * Check suspicious login patterns
     */
    private function checkSuspiciousLoginPatterns(User $user): bool
    {
        $recentEvents = $this->eventLogRepository->findByUser($user, 100);

        $failedLogins = 0;
        $differentIPs = [];
        $suspiciousUserAgents = 0;

        foreach ($recentEvents as $event) {
            if ($event->getEventType() === 'auth.failed_login') {
                $failedLogins++;
            }

            if ($event->getIpAddress()) {
                $differentIPs[$event->getIpAddress()] = true;
            }

            if ($event->getUserAgent() && $this->isSuspiciousUserAgent($event->getUserAgent())) {
                $suspiciousUserAgents++;
            }
        }

        return $failedLogins > 5 || count($differentIPs) > 10 || $suspiciousUserAgents > 3;
    }

    /**
     * Check if user agent is suspicious
     */
    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $suspiciousPatterns = [
            'bot', 'crawler', 'spider', 'scraper',
            'curl', 'wget', 'python', 'java',
            'automated', 'headless'
        ];

        $userAgentLower = strtolower($userAgent);

        foreach ($suspiciousPatterns as $pattern) {
            if (str_contains($userAgentLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check referral abuse
     */
    private function checkReferralAbuse(User $user): bool
    {
        $referrals = $this->userRepository->findBy(['referrer' => $user]);

        if (count($referrals) < 3) {
            return false;
        }

        // Check for self-referral patterns
        $suspiciousPatterns = 0;

        foreach ($referrals as $referral) {
            // Same IP
            if ($referral->getRegistrationIp() === $user->getRegistrationIp()) {
                $suspiciousPatterns++;
            }

            // Similar username
            if ($this->haveSimilarUsernames($user->getUsername(), $referral->getUsername())) {
                $suspiciousPatterns++;
            }

            // No activity after registration
            if ($this->depositRepository->count(['user' => $referral]) === 0) {
                $suspiciousPatterns++;
            }
        }

        return $suspiciousPatterns >= count($referrals) * 0.5;
    }

    /**
     * Log suspicious activity
     */
    private function logSuspiciousActivity(User $user, array $activities, int $riskScore): void
    {
        $this->logEvent($user, 'fraud.suspicious_activity_detected', [
            'activities' => $activities,
            'risk_score' => $riskScore
        ]);

        // Update user risk score
        $user->setRiskScore($riskScore);

        // Flag user if high risk
        if ($riskScore >= $this->config['high_risk_threshold']) {
            $user->setFlagged(true);
            $user->setFlagReason('Automated fraud detection: ' . implode(', ', $activities));

            // Notify admins
            $this->notificationService->notifyAdmins(
                sprintf(
                    "🚨 High-risk user detected!\n\n" .
                    "User: %s (ID: %d)\n" .
                    "Risk Score: %d\n" .
                    "Activities: %s",
                    $user->getUsername(),
                    $user->getId(),
                    $riskScore,
                    implode(', ', $activities)
                )
            );
        }

        $this->entityManager->flush();
    }

    /**
     * Log event
     */
    private function logEvent(User $user, string $type, array $data = []): void
    {
        $event = new EventLog();
        $event->setUser($user);
        $event->setEventType($type);
        $event->setEventData($data);
        $event->setIpAddress($_SERVER['REMOTE_ADDR'] ?? null);
        $event->setUserAgent($_SERVER['HTTP_USER_AGENT'] ?? null);
        $event->setCreatedAt(new DateTime());

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->logger->warning('Fraud event logged', [
            'user_id' => $user->getId(),
            'type' => $type,
            'data' => $data
        ]);
    }

    /**
     * Check deposit for fraud
     */
    public function checkDeposit(Deposit $deposit): bool
    {
        $user = $deposit->getUser();

        // Check if user is already flagged
        if ($user->isFlagged()) {
            $this->logEvent($user, 'fraud.deposit_attempt_while_flagged', [
                'deposit_id' => $deposit->getId(),
                'amount' => $deposit->getAmount()
            ]);
            return false;
        }

        // Check velocity limits
        if (!$this->checkDepositVelocity($user, $deposit->getAmount())) {
            return false;
        }

        // Check for duplicate transactions
        if ($this->isDuplicateTransaction($deposit)) {
            return false;
        }

        // Check amount patterns
        if ($this->isSuspiciousAmount($deposit->getAmount())) {
            $this->logEvent($user, 'fraud.suspicious_deposit_amount', [
                'amount' => $deposit->getAmount()
            ]);
        }

        return true;
    }

    /**
     * Check deposit velocity
     */
    private function checkDepositVelocity(User $user, float $amount): bool
    {
        $limits = $this->config['velocity_limits']['deposits'];

        // Check hourly limit
        $hourlyTotal = $this->depositRepository->getUserTotalInPeriod(
            $user,
            new DateTime('-1 hour')
        );

        if ($hourlyTotal + $amount > $limits['hourly']) {
            $this->logEvent($user, 'fraud.deposit_velocity_exceeded', [
                'period' => 'hourly',
                'limit' => $limits['hourly'],
                'attempted' => $hourlyTotal + $amount
            ]);
            return false;
        }

        // Check daily limit
        $dailyTotal = $this->depositRepository->getUserTotalInPeriod(
            $user,
            new DateTime('-24 hours')
        );

        if ($dailyTotal + $amount > $limits['daily']) {
            $this->logEvent($user, 'fraud.deposit_velocity_exceeded', [
                'period' => 'daily',
                'limit' => $limits['daily'],
                'attempted' => $dailyTotal + $amount
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check for duplicate transaction
     */
    private function isDuplicateTransaction(Deposit $deposit): bool
    {
        $existing = $this->depositRepository->findOneBy([
            'txHash' => $deposit->getTxHash()
        ]);

        if ($existing && $existing->getId() !== $deposit->getId()) {
            $this->logEvent($deposit->getUser(), 'fraud.duplicate_transaction', [
                'txHash' => $deposit->getTxHash()
            ]);
            return true;
        }

        return false;
    }

    /**
     * Check if amount is suspicious
     */
    private function isSuspiciousAmount(float $amount): bool
    {
        // Check for amounts just below reporting thresholds
        $thresholds = [9999, 4999, 2999];

        foreach ($thresholds as $threshold) {
            if ($amount >= $threshold * 0.95 && $amount <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check withdrawal for fraud
     */
    public function checkWithdrawal(Withdrawal $withdrawal): bool
    {
        $user = $withdrawal->getUser();

        // Check if user is flagged
        if ($user->isFlagged()) {
            $this->logEvent($user, 'fraud.withdrawal_attempt_while_flagged', [
                'withdrawal_id' => $withdrawal->getId(),
                'amount' => $withdrawal->getAmount()
            ]);
            return false;
        }

        // Check withdrawal velocity
        if (!$this->checkWithdrawalVelocity($user, $withdrawal->getAmount())) {
            return false;
        }

        // Check address changes
        if ($this->hasRecentAddressChange($user, $withdrawal->getAddress())) {
            $this->logEvent($user, 'fraud.withdrawal_to_new_address', [
                'address' => $withdrawal->getAddress()
            ]);

            // Require additional verification
            $withdrawal->setRequiresAdditionalVerification(true);
        }

        // Check if trying to withdraw immediately after deposit
        if ($this->hasRecentDeposit($user, 24)) {
            $this->logEvent($user, 'fraud.quick_withdrawal_after_deposit');
        }

        return true;
    }

    /**
     * Check withdrawal velocity
     */
    private function checkWithdrawalVelocity(User $user, float $amount): bool
    {
        $limits = $this->config['velocity_limits']['withdrawals'];

        // Check daily limit
        $dailyTotal = $this->withdrawalRepository->getUserTotalInPeriod(
            $user,
            new DateTime('-24 hours')
        );

        if ($dailyTotal + $amount > $limits['daily']) {
            $this->logEvent($user, 'fraud.withdrawal_velocity_exceeded', [
                'period' => 'daily',
                'limit' => $limits['daily'],
                'attempted' => $dailyTotal + $amount
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check for recent address change
     */
    private function hasRecentAddressChange(User $user, string $newAddress): bool
    {
        $recentWithdrawals = $this->withdrawalRepository->findRecentByUser($user, 30);

        if (empty($recentWithdrawals)) {
            return false;
        }

        $lastAddress = $recentWithdrawals[0]->getAddress();

        return $lastAddress !== $newAddress;
    }

    /**
     * Check for recent deposit
     */
    private function hasRecentDeposit(User $user, int $hours): bool
    {
        $count = $this->depositRepository->countUserDepositsInPeriod(
            $user,
            new DateTime("-{$hours} hours")
        );

        return $count > 0;
    }
}