<?php

namespace App\Command;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user',
)]
class CreateAdminCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface      $entityManager,
        UserPasswordHasherInterface $passwordHasher
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address')
            ->addArgument('telegram', InputArgument::REQUIRED, 'Admin Telegram username')
            ->addOption('super-admin', null, InputOption::VALUE_NONE, 'Create super admin with all permissions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $telegramUsername = $input->getArgument('telegram');
        $isSuperAdmin = $input->getOption('super-admin');

        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $email
        ]);

        if ($existingUser) {
            $io->error('User with this email already exists!');
            return Command::FAILURE;
        }

        // Create admin user
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($telegramUsername);

        // Generate secure password
        $password = $this->generateSecurePassword();
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Set admin roles
        $roles = ['ROLE_USER', 'ROLE_ADMIN'];
        if ($isSuperAdmin) {
            $roles[] = 'ROLE_SUPER_ADMIN';
        }
        $user->setRoles($roles);

        // Set other properties
        $user->setIsActive(true);
        $user->setEmailVerified(true);
        $user->setReferralCode($this->generateReferralCode());
        $user->setCreatedAt(new DateTime());
        $user->setPreferredLocale('en');
        $user->setTwoFactorEnabled(true); // Admins must use 2FA

        // Generate 2FA secret
        $secret = $this->generate2FASecret();
        $user->setTwoFactorSecret($secret);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('Admin user created successfully!');

        $io->table(
            ['Field', 'Value'],
            [
                ['Email', $email],
                ['Username', $telegramUsername],
                ['Password', $password],
                ['Roles', implode(', ', $roles)],
                ['2FA Secret', $secret],
                ['User ID', $user->getId()],
            ]
        );

        $io->warning([
            'IMPORTANT: Save these credentials securely!',
            'The password and 2FA secret will not be shown again.',
            '',
            'To set up 2FA:',
            '1. Install Google Authenticator or similar app',
            '2. Add account manually using the secret key above',
            '3. Or use the QR code that will be shown on first login'
        ]);

        if (!$user->getTelegramUserId()) {
            $io->note([
                'Telegram account not linked yet.',
                'The admin must:',
                '1. Start your Telegram bot',
                '2. Send /start command',
                '3. The account will be linked automatically'
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Generate secure password
     */
    private function generateSecurePassword(): string
    {
        $length = 16;
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}|;:,.<>?';

        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $allChars = $uppercase . $lowercase . $numbers . $special;

        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Generate referral code
     */
    private function generateReferralCode(): string
    {
        do {
            $code = 'ADMIN' . strtoupper(bin2hex(random_bytes(3)));
        } while ($this->entityManager->getRepository(User::class)->findOneBy(['referralCode' => $code]));

        return $code;
    }

    /**
     * Generate 2FA secret
     */
    private function generate2FASecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $secret;
    }
}