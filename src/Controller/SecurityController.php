<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Security\TwoFactorService;
use DateTime;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private TwoFactorService $twoFactorService;
    private LoggerInterface $logger;

    public function __construct(
        TwoFactorService $twoFactorService,
        LoggerInterface  $logger
    )
    {
        $this->twoFactorService = $twoFactorService;
        $this->logger = $logger;
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error
        ]);
    }

    #[Route('/admin/login', name: 'admin_login')]
    public function adminLogin(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() && $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/admin_login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This method can be blank - it will be intercepted by the logout key on your firewall
        throw new LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/2fa', name: 'app_2fa')]
    public function twoFactor(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User || !$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $code = $request->request->get('code');

            if ($this->twoFactorService->verifyCode($user, $code)) {
                // Mark 2FA as completed in session
                $request->getSession()->set('2fa_completed', true);

                $this->logger->info('2FA verification successful', [
                    'user_id' => $user->getId()
                ]);

                // Redirect based on role
                if ($this->isGranted('ROLE_ADMIN')) {
                    return $this->redirectToRoute('admin_dashboard');
                }

                return $this->redirectToRoute('app_home');
            }

            $this->addFlash('error', 'Invalid verification code');
        }

        return $this->render('security/2fa.html.twig');
    }

    #[Route('/2fa/backup', name: 'app_2fa_backup')]
    public function twoFactorBackup(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User || !$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $code = $request->request->get('backup_code');

            if ($this->twoFactorService->verifyBackupCode($user, $code)) {
                // Mark 2FA as completed in session
                $request->getSession()->set('2fa_completed', true);

                $this->logger->info('2FA backup code used', [
                    'user_id' => $user->getId()
                ]);

                $this->addFlash('warning', 'Backup code used. Please generate new backup codes.');

                // Redirect based on role
                if ($this->isGranted('ROLE_ADMIN')) {
                    return $this->redirectToRoute('admin_dashboard');
                }

                return $this->redirectToRoute('app_home');
            }

            $this->addFlash('error', 'Invalid backup code');
        }

        return $this->render('security/2fa_backup.html.twig');
    }

    #[Route('/2fa/setup', name: 'app_2fa_setup')]
    public function setupTwoFactor(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isTwoFactorEnabled()) {
            $this->addFlash('info', '2FA is already enabled');
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $code = $request->request->get('code');

            try {
                if ($this->twoFactorService->enable2FA($user, $code)) {
                    $backupCodes = $user->getTwoFactorBackupCodes();

                    return $this->render('security/2fa_backup_codes.html.twig', [
                        'backup_codes' => $backupCodes
                    ]);
                }
            } catch (Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        // Generate new secret if not exists
        if (!$user->getTwoFactorSecret()) {
            $this->twoFactorService->generateSecret($user);
        }

        $qrCode = $this->twoFactorService->getQrCode($user);

        return $this->render('security/2fa_setup.html.twig', [
            'qr_code' => $qrCode
        ]);
    }

    #[Route('/2fa/disable', name: 'app_2fa_disable')]
    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$user->isTwoFactorEnabled()) {
            $this->addFlash('info', '2FA is not enabled');
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');

            try {
                if ($this->twoFactorService->disable2FA($user, $password)) {
                    $this->addFlash('success', '2FA has been disabled');
                    return $this->redirectToRoute('app_home');
                }
            } catch (Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('security/2fa_disable.html.twig');
    }

    #[Route('/telegram/auth', name: 'telegram_auth')]
    public function telegramAuth(Request $request): Response
    {
        $telegramData = $request->query->all();

        if (empty($telegramData) || !isset($telegramData['hash'])) {
            $this->addFlash('error', 'Invalid Telegram data');
            return $this->redirectToRoute('app_login');
        }

        try {
            // Verify Telegram data
            if (!$this->verifyTelegramData($telegramData)) {
                throw new Exception('Invalid Telegram authentication');
            }

            // Find or create user
            $user = $this->userRepository->findOneBy(['telegramUserId' => $telegramData['id']]);

            if (!$user) {
                // Create new user from Telegram
                $user = new User();
                $user->setTelegramUserId($telegramData['id']);
                $user->setUsername($telegramData['username'] ?? 'user_' . $telegramData['id']);
                $user->setFirstName($telegramData['first_name'] ?? null);
                $user->setLastName($telegramData['last_name'] ?? null);
                $user->setRoles(['ROLE_USER']);
                $user->setIsActive(true);

                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            // Authenticate user
            // This would typically be handled by a custom authenticator

            $this->logger->info('User authenticated via Telegram', [
                'user_id' => $user->getId(),
                'telegram_id' => $telegramData['id']
            ]);

            return $this->redirectToRoute('app_home');

        } catch (Exception $e) {
            $this->logger->error('Telegram authentication failed', [
                'error' => $e->getMessage(),
                'data' => $telegramData
            ]);

            $this->addFlash('error', 'Authentication failed');
            return $this->redirectToRoute('app_login');
        }
    }

    /**
     * Verify Telegram data
     */
    private function verifyTelegramData(array $data): bool
    {
        $checkHash = $data['hash'];
        unset($data['hash']);

        $dataCheckArr = [];
        foreach ($data as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash('sha256', $_ENV['TELEGRAM_BOT_TOKEN'], true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (strcmp($hash, $checkHash) !== 0) {
            return false;
        }

        // Check auth date
        if ((time() - $data['auth_date']) > 86400) {
            return false;
        }

        return true;
    }

    #[Route('/security/check', name: 'security_check')]
    public function securityCheck(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $securityScore = 0;
        $recommendations = [];

        // Check 2FA
        if ($user->isTwoFactorEnabled()) {
            $securityScore += 40;
        } else {
            $recommendations[] = 'Enable two-factor authentication for better security';
        }

        // Check password age
        $passwordAge = $user->getPasswordChangedAt() ?
            $user->getPasswordChangedAt()->diff(new DateTime())->days : 999;

        if ($passwordAge < 90) {
            $securityScore += 20;
        } else {
            $recommendations[] = 'Change your password regularly (at least every 90 days)';
        }

        // Check email verification
        if ($user->isEmailVerified()) {
            $securityScore += 20;
        } else {
            $recommendations[] = 'Verify your email address';
        }

        // Check recent activity
        $recentSuspiciousActivity = $this->eventLogRepository->findSuspiciousActivities(
            new DateTime('-7 days')
        );

        if (empty($recentSuspiciousActivity)) {
            $securityScore += 20;
        } else {
            $recommendations[] = 'Review recent suspicious activities in your account';
        }

        return $this->render('security/check.html.twig', [
            'security_score' => $securityScore,
            'recommendations' => $recommendations,
            'recent_activity' => $recentSuspiciousActivity
        ]);
    }
}