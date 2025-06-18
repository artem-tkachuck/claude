<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class TelegramAuthenticator extends AbstractAuthenticator
{
    private UserRepository $userRepository;
    private JWTTokenManagerInterface $jwtManager;
    private string $appSecret;

    public function __construct(
        UserRepository           $userRepository,
        JWTTokenManagerInterface $jwtManager,
        string                   $appSecret
    )
    {
        $this->userRepository = $userRepository;
        $this->jwtManager = $jwtManager;
        $this->appSecret = $appSecret;
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-Telegram-Auth-Token');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->headers->get('X-Telegram-Auth-Token');

        if (!$token) {
            throw new AuthenticationException('No auth token provided');
        }

        // Verify token structure
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new AuthenticationException('Invalid token format');
        }

        [$payload, $signature] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new AuthenticationException('Invalid token signature');
        }

        // Decode payload
        $data = json_decode(base64_decode($payload), true);
        if (!$data || !isset($data['telegram_id'], $data['timestamp'])) {
            throw new AuthenticationException('Invalid token payload');
        }

        // Check token expiration (1 hour)
        if (time() - $data['timestamp'] > 3600) {
            throw new AuthenticationException('Token expired');
        }

        return new SelfValidatingPassport(
            new UserBadge($data['telegram_id'], function ($telegramId) {
                $user = $this->userRepository->findOneBy(['telegramId' => $telegramId]);
                if (!$user) {
                    throw new AuthenticationException('User not found');
                }
                if (!$user->isActive()) {
                    throw new AuthenticationException('User account is disabled');
                }
                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Update last activity
        $user = $token->getUser();
        if ($user instanceof User) {
            $user->setLastActivityAt(new DateTimeImmutable());
            $this->userRepository->save($user, true);
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessageKey(),
        ], Response::HTTP_UNAUTHORIZED);
    }
}