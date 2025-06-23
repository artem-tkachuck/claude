<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\Security\TwoFactorService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

class AuthenticationListener implements EventSubscriberInterface
{
    private TwoFactorService $twoFactorService;
    private RequestStack $requestStack;
    private LoggerInterface $logger;

    public function __construct(
        TwoFactorService $twoFactorService,
        RequestStack     $requestStack,
        LoggerInterface  $logger
    )
    {
        $this->twoFactorService = $twoFactorService;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'lexik_jwt_authentication.on_authentication_success' => 'onAuthenticationSuccess',
            'lexik_jwt_authentication.on_authentication_failure' => 'onAuthenticationFailure',
            'lexik_jwt_authentication.on_jwt_created' => 'onJWTCreated',
            'lexik_jwt_authentication.on_jwt_decoded' => 'onJWTDecoded',
            'lexik_jwt_authentication.on_jwt_invalid' => 'onJWTInvalid',
        ];
    }

    /**
     * Handle authentication success
     */
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Add user information to response
        $data['user'] = [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'deposit_balance' => $user->getDepositBalance(),
            'bonus_balance' => $user->getBonusBalance(),
            'two_factor_enabled' => $user->isTwoFactorEnabled(),
            'requires_2fa' => $this->requires2FA($user)
        ];

        // Add refresh token
        $data['refresh_token'] = $this->generateRefreshToken($user);

        // Add session information
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $data['session'] = [
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'created_at' => time()
            ];
        }

        $event->setData($data);

        $this->logger->info('JWT authentication successful', [
            'user_id' => $user->getId(),
            'username' => $user->getUsername()
        ]);
    }

    /**
     * Check if user requires 2FA
     */
    private function requires2FA(User $user): bool
    {
        // Admins always require 2FA
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Check user preference
        return $user->isTwoFactorEnabled();
    }

    /**
     * Generate refresh token
     */
    private function generateRefreshToken(User $user): string
    {
        // In a real implementation, this would generate and store a refresh token
        $token = bin2hex(random_bytes(32));

        // Store refresh token with expiration (7 days)
        // This would typically be stored in database or Redis

        return $token;
    }

    /**
     * Handle authentication failure
     */
    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        $response = $event->getResponse();

        // Enhance error response
        $data = json_decode($response->getContent(), true);

        $data['error'] = [
            'code' => 'AUTHENTICATION_FAILED',
            'message' => $exception->getMessage(),
            'timestamp' => time()
        ];

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $this->logger->warning('JWT authentication failed', [
                'reason' => $exception->getMessage(),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent')
            ]);
        }

        $response->setContent(json_encode($data));
        $event->setResponse($response);
    }

    /**
     * Customize JWT payload
     */
    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Add custom claims
        $payload['user_id'] = $user->getId();
        $payload['username'] = $user->getUsername();
        $payload['roles'] = $user->getRoles();
        $payload['two_factor_completed'] = !$this->requires2FA($user);

        // Add session information
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $payload['session'] = [
                'ip' => $request->getClientIp(),
                'fingerprint' => $this->generateFingerprint($request)
            ];
        }

        // Set custom expiration based on user type
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            $payload['exp'] = time() + 3600; // 1 hour for admins
        } else {
            $payload['exp'] = time() + 86400; // 24 hours for regular users
        }

        $event->setData($payload);

        $this->logger->info('JWT created', [
            'user_id' => $user->getId(),
            'expires_at' => date('Y-m-d H:i:s', $payload['exp'])
        ]);
    }

    /**
     * Generate session fingerprint
     */
    private function generateFingerprint($request): string
    {
        $components = [
            $request->headers->get('User-Agent', ''),
            $request->headers->get('Accept-Language', ''),
            $request->headers->get('Accept-Encoding', ''),
            // Don't include IP to allow for mobile users changing networks
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Validate JWT payload
     */
    public function onJWTDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return;
        }

        // Validate session fingerprint
        if (isset($payload['session']['fingerprint'])) {
            $currentFingerprint = $this->generateFingerprint($request);

            if ($payload['session']['fingerprint'] !== $currentFingerprint) {
                $event->markAsInvalid();

                $this->logger->warning('JWT fingerprint mismatch', [
                    'user_id' => $payload['user_id'] ?? 'unknown',
                    'expected' => $payload['session']['fingerprint'],
                    'actual' => $currentFingerprint
                ]);

                return;
            }
        }

        // Validate IP if strict mode enabled
        if (isset($payload['session']['ip']) && $this->isStrictIpCheckEnabled()) {
            $currentIp = $request->getClientIp();

            if ($payload['session']['ip'] !== $currentIp) {
                $event->markAsInvalid();

                $this->logger->warning('JWT IP mismatch', [
                    'user_id' => $payload['user_id'] ?? 'unknown',
                    'expected_ip' => $payload['session']['ip'],
                    'actual_ip' => $currentIp
                ]);

                return;
            }
        }

        // Check if token is blacklisted
        if ($this->isTokenBlacklisted($payload)) {
            $event->markAsInvalid();

            $this->logger->warning('Blacklisted JWT used', [
                'user_id' => $payload['user_id'] ?? 'unknown',
                'jti' => $payload['jti'] ?? 'unknown'
            ]);

            return;
        }

        // Validate 2FA requirement
        if (isset($payload['two_factor_completed']) && !$payload['two_factor_completed']) {
            // Check if 2FA was completed in session
            $session = $request->getSession();
            if ($session && !$session->get('2fa_completed')) {
                // Allow only specific endpoints without 2FA
                $allowedPaths = ['/api/auth/2fa', '/api/auth/logout'];

                if (!in_array($request->getPathInfo(), $allowedPaths)) {
                    $event->markAsInvalid();

                    $this->logger->info('JWT requires 2FA completion', [
                        'user_id' => $payload['user_id'] ?? 'unknown'
                    ]);
                }
            }
        }
    }

    /**
     * Check if strict IP checking is enabled
     */
    private function isStrictIpCheckEnabled(): bool
    {
        // This could be a configuration option
        return $_ENV['JWT_STRICT_IP_CHECK'] ?? false;
    }

    /**
     * Check if token is blacklisted
     */
    private function isTokenBlacklisted(array $payload): bool
    {
        if (!isset($payload['jti'])) {
            return false;
        }

        // In a real implementation, check against blacklist in Redis/database
        // This is a placeholder
        return false;
    }

    /**
     * Handle invalid JWT
     */
    public function onJWTInvalid(JWTInvalidEvent $event): void
    {
        $response = $event->getResponse();
        $request = $this->requestStack->getCurrentRequest();

        // Enhance error response
        $data = [
            'error' => [
                'code' => 'JWT_INVALID',
                'message' => 'Invalid or expired token',
                'timestamp' => time()
            ]
        ];

        if ($request) {
            $this->logger->warning('Invalid JWT used', [
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'path' => $request->getPathInfo()
            ]);
        }

        $response->setContent(json_encode($data));
        $response->setStatusCode(401);

        $event->setResponse($response);
    }
}