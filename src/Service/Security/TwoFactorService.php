<?php

namespace App\Service\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use Exception;
use OTPHP\TOTP;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TwoFactorService
{
    private string $issuer;
    private UserRepository $userRepository;
    private EncryptionService $encryptionService;
    private LoggerInterface $logger;

    public function __construct(
        #[Autowire('%env(APP_NAME)%')] string $issuer,
        UserRepository                        $userRepository,
        EncryptionService                     $encryptionService,
        LoggerInterface                       $logger
    )
    {
        $this->issuer = $issuer;
        $this->userRepository = $userRepository;
        $this->encryptionService = $encryptionService;
        $this->logger = $logger;
    }

    /**
     * Generate 2FA secret for user
     */
    public function generateSecret(User $user): string
    {
        $totp = TOTP::create();
        $totp->setLabel($user->getEmail());
        $totp->setIssuer($this->issuer);

        $secret = $totp->getSecret();

        // Encrypt and save secret
        $encryptedSecret = $this->encryptionService->encrypt($secret);
        $user->setTwoFactorSecret($encryptedSecret);

        $this->userRepository->save($user, true);

        $this->logger->info('2FA secret generated', [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail()
        ]);

        return $secret;
    }

    /**
     * Get QR code for 2FA setup
     */
    public function getQrCode(User $user): string
    {
        if (!$user->getTwoFactorSecret()) {
            throw new RuntimeException('User has no 2FA secret');
        }

        $secret = $this->encryptionService->decrypt($user->getTwoFactorSecret());

        $totp = TOTP::create($secret);
        $totp->setLabel($user->getEmail());
        $totp->setIssuer($this->issuer);

        $provisioningUri = $totp->getProvisioningUri();

        $result = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
            ->data($provisioningUri)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->size(300)
            ->margin(10)
            ->roundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->build();

        return $result->getDataUri();
    }

    /**
     * Enable 2FA for user
     */
    public function enable2FA(User $user, string $code): bool
    {
        if ($user->isTwoFactorEnabled()) {
            throw new RuntimeException('2FA is already enabled');
        }

        if (!$this->verifyCode($user, $code)) {
            throw new RuntimeException('Invalid verification code');
        }

        $user->setTwoFactorEnabled(true);
        $user->setTwoFactorBackupCodes($this->generateBackupCodes());

        $this->userRepository->save($user, true);

        $this->logger->info('2FA enabled', [
            'user_id' => $user->getId()
        ]);

        return true;
    }

    /**
     * Verify 2FA code
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->getTwoFactorSecret()) {
            return false;
        }

        try {
            $secret = $this->encryptionService->decrypt($user->getTwoFactorSecret());

            $totp = TOTP::create($secret);

            // Allow 1 window before and after for clock skew
            $isValid = $totp->verify($code, null, 1);

            if ($isValid) {
                $this->logger->info('2FA code verified successfully', [
                    'user_id' => $user->getId()
                ]);
            } else {
                $this->logger->warning('2FA code verification failed', [
                    'user_id' => $user->getId()
                ]);
            }

            return $isValid;
        } catch (Exception $e) {
            $this->logger->error('2FA verification error', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate backup codes
     *
     * @return array<string>
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf(
                '%s-%s',
                bin2hex(random_bytes(3)),
                bin2hex(random_bytes(3))
            );
        }

        return $codes;
    }

    /**
     * Disable 2FA for user
     */
    public function disable2FA(User $user, string $password): bool
    {
        // Verify password before disabling 2FA
        if (!password_verify($password, $user->getPassword())) {
            throw new RuntimeException('Invalid password');
        }

        $user->setTwoFactorEnabled(false);
        $user->setTwoFactorSecret(null);
        $user->setTwoFactorBackupCodes([]);

        $this->userRepository->save($user, true);

        $this->logger->info('2FA disabled', [
            'user_id' => $user->getId()
        ]);

        return true;
    }

    /**
     * Verify backup code
     */
    public function verifyBackupCode(User $user, string $code): bool
    {
        $backupCodes = $user->getTwoFactorBackupCodes();

        if (!in_array($code, $backupCodes, true)) {
            return false;
        }

        // Remove used backup code
        $backupCodes = array_values(array_diff($backupCodes, [$code]));
        $user->setTwoFactorBackupCodes($backupCodes);

        $this->userRepository->save($user, true);

        $this->logger->info('Backup code used', [
            'user_id' => $user->getId(),
            'remaining_codes' => count($backupCodes)
        ]);

        return true;
    }

    /**
     * Regenerate backup codes
     *
     * @return array<string>
     */
    public function regenerateBackupCodes(User $user, string $password): array
    {
        // Verify password
        if (!password_verify($password, $user->getPassword())) {
            throw new RuntimeException('Invalid password');
        }

        $newCodes = $this->generateBackupCodes();
        $user->setTwoFactorBackupCodes($newCodes);

        $this->userRepository->save($user, true);

        $this->logger->info('Backup codes regenerated', [
            'user_id' => $user->getId()
        ]);

        return $newCodes;
    }

    /**
     * Get 2FA status
     *
     * @return array<string, mixed>
     */
    public function get2FAStatus(User $user): array
    {
        return [
            'enabled' => $user->isTwoFactorEnabled(),
            'backup_codes_count' => count($user->getTwoFactorBackupCodes()),
            'required' => $this->requires2FA($user),
            'method' => 'totp'
        ];
    }

    /**
     * Check if user needs 2FA
     */
    public function requires2FA(User $user): bool
    {
        // Admins always require 2FA
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Check if user has enabled 2FA
        return $user->isTwoFactorEnabled();
    }
}
