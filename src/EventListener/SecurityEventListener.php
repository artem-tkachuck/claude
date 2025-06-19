<?php

namespace App\EventListener;

use App\Entity\EventLog;
use App\Entity\User;
use App\Service\Notification\NotificationService;
use App\Service\Security\FraudDetectionService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Event\AuthenticationFailureEvent;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

class SecurityEventListener implements EventSubscriberInterface
{
    private EntityManagerInterface $entityManager;
    private FraudDetectionService $fraudDetectionService;
    private NotificationService $notificationService;
    private RequestStack $requestStack;
    private LoggerInterface $logger;
    private array $failedAttempts = [];

    public function __construct(
        EntityManagerInterface $entityManager,
        FraudDetectionService  $fraudDetectionService,
        NotificationService    $notificationService,
        RequestStack           $requestStack,
        LoggerInterface        $logger
    )
    {
        $this->entityManager = $entityManager;
        $this->fraudDetectionService = $fraudDetectionService;
        $this->notificationService = $notificationService;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AuthenticationSuccessEvent::class => 'onAuthenticationSuccess',
            AuthenticationFailureEvent::class => 'onAuthenticationFailure',
            InteractiveLoginEvent::class => 'onInteractiveLogin',
            LogoutEvent::class => 'onLogout',
            SwitchUserEvent::class => 'onSwitchUser',
        ];
    }

    /**
     * Handle successful authentication
     */
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $token = $event->getAuthenticationToken();
        $user = $token->getUser();

        if (!$user instanceof User) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // Clear failed attempts
        $ip = $request->getClientIp();
        unset($this->failedAttempts[$ip]);

        // Log successful authentication
        $this->logEvent($user, 'auth.success', [
            'method' => $token->getProviderKey() ?? 'unknown',
            'ip' => $ip,
            'user_agent' => $request->headers->get('User-Agent')
        ]);

        // Update last login
        $user->setLastLoginAt(new DateTime());
        $user->setLastLoginIp($ip);
        $this->entityManager->flush();

        // Check for suspicious activity
        $this->checkSuspiciousLogin($user, $request);
    }

    /**
     * Log security event
     */
    private function logEvent(?User $user, string $type, array $data = []): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $event = new EventLog();
        $event->setUser($user);
        $event->setEventType($type);
        $event->setEventData($data);
        $event->setCreatedAt(new DateTime());

        if ($request) {
            $event->setIpAddress($request->getClientIp());
            $event->setUserAgent($request->headers->get('User-Agent'));
        }

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    /**
     * Check for suspicious login
     */
    private function checkSuspiciousLogin(User $user, $request): void
    {
        $ip = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent');

        // Check for new IP
        $previousLogins = $this->entityManager->getRepository(EventLog::class)->findBy([
            'user' => $user,
            'eventType' => 'auth.success'
        ], ['createdAt' => 'DESC'], 10);

        $knownIps = [];
        foreach ($previousLogins as $login) {
            if ($login->getIpAddress()) {
                $knownIps[] = $login->getIpAddress();
            }
        }

        if (!in_array($ip, $knownIps) && count($knownIps) > 0) {
            // New IP detected
            $this->notificationService->sendSecurityAlert($user, 'new_ip_login', [
                'ip' => $ip,
                'location' => $this->getIpLocation($ip)
            ]);
        }

        // Check for impossible travel
        if ($user->getLastLoginAt() && $user->getLastLoginIp()) {
            $timeDiff = time() - $user->getLastLoginAt()->getTimestamp();

            if ($timeDiff < 3600) { // Within 1 hour
                $distance = $this->calculateIpDistance($user->getLastLoginIp(), $ip);

                if ($distance > 1000) { // More than 1000km
                    $this->logEvent($user, 'security.impossible_travel', [
                        'previous_ip' => $user->getLastLoginIp(),
                        'current_ip' => $ip,
                        'distance' => $distance,
                        'time_diff' => $timeDiff
                    ]);

                    $this->notificationService->sendSecurityAlert($user, 'impossible_travel', [
                        'distance' => $distance,
                        'time_diff' => $timeDiff / 60 // minutes
                    ]);
                }
            }
        }

        // Check for suspicious user agent
        if ($this->isSuspiciousUserAgent($userAgent)) {
            $this->logEvent($user, 'security.suspicious_user_agent', [
                'user_agent' => $userAgent
            ]);
        }
    }

    /**
     * Get IP location (placeholder)
     */
    private function getIpLocation(string $ip): string
    {
        // In production, use a geolocation service
        return 'Unknown Location';
    }

    /**
     * Calculate distance between IPs (placeholder)
     */
    private function calculateIpDistance(string $ip1, string $ip2): float
    {
        // In production, use geolocation to calculate actual distance
        return 0.0;
    }

    /**
     * Check if user agent is suspicious
     */
    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $suspicious = ['bot', 'crawler', 'spider', 'curl', 'wget', 'python', 'scrapy'];

        $userAgentLower = strtolower($userAgent);
        foreach ($suspicious as $pattern) {
            if (strpos($userAgentLower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle authentication failure
     */
    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getAuthenticationException();
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return;
        }

        $ip = $request->getClientIp();
        $username = $exception->getToken() ? $exception->getToken()->getUserIdentifier() : 'unknown';

        // Track failed attempts
        if (!isset($this->failedAttempts[$ip])) {
            $this->failedAttempts[$ip] = [];
        }

        $this->failedAttempts[$ip][] = [
            'time' => time(),
            'username' => $username
        ];

        // Clean old attempts (older than 15 minutes)
        $this->failedAttempts[$ip] = array_filter(
            $this->failedAttempts[$ip],
            fn($attempt) => $attempt['time'] > time() - 900
        );

        // Check if IP should be blocked
        if (count($this->failedAttempts[$ip]) >= 5) {
            $this->handleBruteForceAttempt($ip, $username);
        }

        // Try to find user to log the event
        $user = null;
        if ($username !== 'unknown') {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        }

        // Log failed authentication
        $this->logEvent($user, 'auth.failed', [
            'username' => $username,
            'ip' => $ip,
            'reason' => $exception->getMessage(),
            'attempts' => count($this->failedAttempts[$ip])
        ]);

        $this->logger->warning('Authentication failed', [
            'username' => $username,
            'ip' => $ip,
            'attempts' => count($this->failedAttempts[$ip])
        ]);
    }

    /**
     * Handle brute force attempt
     */
    private function handleBruteForceAttempt(string $ip, string $username): void
    {
        $this->logEvent(null, 'security.brute_force_attempt', [
            'ip' => $ip,
            'username' => $username,
            'attempts' => count($this->failedAttempts[$ip])
        ]);

        // Notify admins
        $this->notificationService->notifyAdmins(
            sprintf(
                "🚨 Brute force attempt detected!\n\n" .
                "IP: %s\n" .
                "Username: %s\n" .
                "Attempts: %d",
                $ip,
                $username,
                count($this->failedAttempts[$ip])
            )
        );

        // Here you could implement IP blocking
        $this->logger->critical('Brute force attempt detected', [
            'ip' => $ip,
            'username' => $username
        ]);
    }

    /**
     * Handle interactive login
     */
    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        // Check if login from new device/location
        $this->checkNewDevice($user, $request);

        // Check if requires 2FA
        if ($user->isTwoFactorEnabled() && !$request->getSession()->get('2fa_completed')) {
            // This would typically redirect to 2FA page
            $this->logger->info('2FA required for user', ['user_id' => $user->getId()]);
        }

        // Run fraud checks
        $fraudCheck = $this->fraudDetectionService->checkUser($user);

        if ($fraudCheck['is_high_risk']) {
            $this->notificationService->sendSecurityAlert($user, 'high_risk_login', [
                'risk_score' => $fraudCheck['risk_score'],
                'activities' => $fraudCheck['activities']
            ]);
        }
    }

    /**
     * Check for new device
     */
    private function checkNewDevice(User $user, $request): void
    {
        $userAgent = $request->headers->get('User-Agent');
        $deviceId = $this->generateDeviceId($userAgent, $request->getClientIp());

        $knownDevices = $user->getKnownDevices() ?? [];

        if (!in_array($deviceId, $knownDevices)) {
            $knownDevices[] = $deviceId;
            $user->setKnownDevices(array_slice($knownDevices, -10)); // Keep last 10 devices

            $this->notificationService->sendSecurityAlert($user, 'new_device_login', [
                'device' => $this->parseUserAgent($userAgent)
            ]);
        }
    }

    /**
     * Generate device ID
     */
    private function generateDeviceId(string $userAgent, string $ip): string
    {
        return md5($userAgent . $ip);
    }

    /**
     * Parse user agent
     */
    private function parseUserAgent(string $userAgent): array
    {
        // Simple parser - in production use a proper library
        $device = [
            'browser' => 'Unknown',
            'os' => 'Unknown',
            'device' => 'Unknown'
        ];

        if (preg_match('/Chrome\/(\d+)/', $userAgent, $matches)) {
            $device['browser'] = 'Chrome ' . $matches[1];
        } elseif (preg_match('/Firefox\/(\d+)/', $userAgent, $matches)) {
            $device['browser'] = 'Firefox ' . $matches[1];
        } elseif (preg_match('/Safari\/(\d+)/', $userAgent, $matches)) {
            $device['browser'] = 'Safari';
        }

        if (strpos($userAgent, 'Windows') !== false) {
            $device['os'] = 'Windows';
        } elseif (strpos($userAgent, 'Mac OS') !== false) {
            $device['os'] = 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            $device['os'] = 'Linux';
        } elseif (strpos($userAgent, 'Android') !== false) {
            $device['os'] = 'Android';
        } elseif (strpos($userAgent, 'iOS') !== false) {
            $device['os'] = 'iOS';
        }

        if (strpos($userAgent, 'Mobile') !== false) {
            $device['device'] = 'Mobile';
        } elseif (strpos($userAgent, 'Tablet') !== false) {
            $device['device'] = 'Tablet';
        } else {
            $device['device'] = 'Desktop';
        }

        return $device;
    }

    /**
     * Handle logout
     */
    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        $this->logEvent($user, 'auth.logout', [
            'ip' => $request->getClientIp(),
            'session_duration' => $this->calculateSessionDuration($user)
        ]);
    }

    /**
     * Calculate session duration
     */
    private function calculateSessionDuration(User $user): int
    {
        if (!$user->getLastLoginAt()) {
            return 0;
        }

        return time() - $user->getLastLoginAt()->getTimestamp();
    }

    /**
     * Handle user switching
     */
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $request = $event->getRequest();
        $targetUser = $event->getTargetUser();

        // Get the original user (admin)
        $token = $event->getToken();
        $originalUser = null;

        if ($token && method_exists($token, 'getOriginalToken')) {
            $originalToken = $token->getOriginalToken();
            if ($originalToken) {
                $originalUser = $originalToken->getUser();
            }
        }

        if ($originalUser instanceof User && $targetUser instanceof User) {
            $this->logEvent($originalUser, 'admin.switch_user', [
                'target_user_id' => $targetUser->getId(),
                'target_username' => $targetUser->getUsername(),
                'ip' => $request->getClientIp()
            ]);

            // Notify the target user
            $this->notificationService->sendSecurityAlert(
                $targetUser,
                'account_accessed_by_admin',
                ['admin_username' => $originalUser->getUsername()]
            );
        }
    }
}