<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    private UserRepository $userRepository;
    private JWTTokenManagerInterface $jwtManager;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        UserRepository           $userRepository,
        JWTTokenManagerInterface $jwtManager,
        EntityManagerInterface   $entityManager,
        LoggerInterface          $logger
    )
    {
        $this->userRepository = $userRepository;
        $this->jwtManager = $jwtManager;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    #[Route('/telegram', name: 'telegram', methods: ['POST'])]
    public function telegramAuth(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (!isset($data['id']) || !isset($data['hash'])) {
                return new JsonResponse([
                    'error' => 'Missing required fields'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Verify Telegram data
            if (!$this->verifyTelegramData($data)) {
                throw new AuthenticationException('Invalid Telegram data');
            }

            // Find or create user
            $user = $this->userRepository->findOneBy(['telegramUserId' => $data['id']]);

            if (!$user) {
                $user = $this->createUserFromTelegram($data);
            } else {
                // Update user info
                $this->updateUserFromTelegram($user, $data);
            }

            // Generate JWT token
            $token = $this->jwtManager->create($user);

            $this->logger->info('User authenticated via Telegram', [
                'user_id' => $user->getId(),
                'telegram_id' => $data['id']
            ]);

            return new JsonResponse([
                'token' => $token,
                'user' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'depositBalance' => $user->getDepositBalance(),
                    'bonusBalance' => $user->getBonusBalance(),
                    'referralCode' => $user->getReferralCode(),
                    'roles' => $user->getRoles()
                ]
            ]);

        } catch (AuthenticationException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_UNAUTHORIZED);
        } catch (Exception $e) {
            $this->logger->error('Telegram auth error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'error' => 'Authentication failed'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify Telegram data
     */
    private function verifyTelegramData(array $data): bool
    {
        $checkHash = $data['hash'];
        unset($data['hash']);

        // Create data check string
        $dataCheckArr = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);

        // Calculate hash
        $secretKey = hash('sha256', $_ENV['TELEGRAM_BOT_TOKEN'], true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (strcmp($hash, $checkHash) !== 0) {
            return false;
        }

        // Check auth date (allow 1 hour)
        if ((time() - $data['auth_date']) > 3600) {
            return false;
        }

        return true;
    }

    /**
     * Create user from Telegram data
     */
    private function createUserFromTelegram(array $data): User
    {
        $user = new User();
        $user->setTelegramUserId($data['id']);
        $user->setUsername($data['username'] ?? 'user_' . $data['id']);
        $user->setFirstName($data['first_name'] ?? null);
        $user->setLastName($data['last_name'] ?? null);
        $user->setReferralCode($this->generateReferralCode());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setCreatedAt(new DateTime());

        if (isset($data['photo_url'])) {
            $user->setPhotoUrl($data['photo_url']);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->logger->info('New user created from Telegram auth', [
            'user_id' => $user->getId(),
            'telegram_id' => $data['id']
        ]);

        return $user;
    }

    /**
     * Generate unique referral code
     */
    private function generateReferralCode(): string
    {
        do {
            $code = substr(md5(uniqid()), 0, 8);
        } while ($this->userRepository->findOneBy(['referralCode' => $code]));

        return $code;
    }

    /**
     * Update user from Telegram data
     */
    private function updateUserFromTelegram(User $user, array $data): void
    {
        $updated = false;

        if (isset($data['username']) && $user->getUsername() !== $data['username']) {
            $user->setUsername($data['username']);
            $updated = true;
        }

        if (isset($data['first_name']) && $user->getFirstName() !== $data['first_name']) {
            $user->setFirstName($data['first_name']);
            $updated = true;
        }

        if (isset($data['last_name']) && $user->getLastName() !== $data['last_name']) {
            $user->setLastName($data['last_name']);
            $updated = true;
        }

        if (isset($data['photo_url']) && $user->getPhotoUrl() !== $data['photo_url']) {
            $user->setPhotoUrl($data['photo_url']);
            $updated = true;
        }

        if ($updated) {
            $user->setUpdatedAt(new DateTime());
            $this->entityManager->flush();
        }
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $refreshToken = $data['refresh_token'] ?? null;

            if (!$refreshToken) {
                return new JsonResponse([
                    'error' => 'Refresh token required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate refresh token and get user
            $user = $this->validateRefreshToken($refreshToken);

            if (!$user) {
                throw new AuthenticationException('Invalid refresh token');
            }

            // Generate new tokens
            $token = $this->jwtManager->create($user);
            $newRefreshToken = $this->generateRefreshToken($user);

            return new JsonResponse([
                'token' => $token,
                'refresh_token' => $newRefreshToken
            ]);

        } catch (AuthenticationException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Validate refresh token
     */
    private function validateRefreshToken(string $token): ?User
    {
        // In a real implementation, you would store refresh tokens in database
        // For now, we'll decode and validate

        try {
            // This is a simplified version
            // You should implement proper refresh token storage and validation
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate refresh token
     */
    private function generateRefreshToken(User $user): string
    {
        // In a real implementation, generate and store refresh token
        // For now, return a dummy token
        return bin2hex(random_bytes(32));
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // JWT tokens are stateless, so we just return success
        // In a real implementation, you might want to blacklist the token

        return new JsonResponse([
            'message' => 'Successfully logged out'
        ]);
    }

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => 'Unauthorized'
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'depositBalance' => $user->getDepositBalance(),
                'bonusBalance' => $user->getBonusBalance(),
                'totalBalance' => $user->getTotalBalance(),
                'referralCode' => $user->getReferralCode(),
                'preferredLocale' => $user->getPreferredLocale(),
                'twoFactorEnabled' => $user->isTwoFactorEnabled(),
                'notificationsEnabled' => $user->isNotificationsEnabled(),
                'createdAt' => $user->getCreatedAt()->format('c'),
                'depositAddress' => $user->getDepositAddress(),
                'roles' => $user->getRoles()
            ]
        ]);
    }
}