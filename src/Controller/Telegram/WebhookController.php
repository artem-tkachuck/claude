<?php

namespace App\Controller\Telegram;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Telegram\TelegramBotService;
use App\Service\Transaction\TransactionService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    private TelegramBotService $telegramService;
    private UserRepository $userRepository;
    private TransactionService $transactionService;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        TelegramBotService     $telegramService,
        UserRepository         $userRepository,
        TransactionService     $transactionService,
        EntityManagerInterface $entityManager,
        LoggerInterface        $logger
    )
    {
        $this->telegramService = $telegramService;
        $this->userRepository = $userRepository;
        $this->transactionService = $transactionService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    #[Route('/telegram/webhook/{token}', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(Request $request, string $token): Response
    {
        // Verify webhook token
        if ($token !== $_ENV['TELEGRAM_WEBHOOK_TOKEN']) {
            return new Response('Unauthorized', 401);
        }

        // Verify request from Telegram
        $secretToken = $request->headers->get('X-Telegram-Bot-Api-Secret-Token');
        if ($secretToken !== $_ENV['TELEGRAM_SECRET_TOKEN']) {
            return new Response('Unauthorized', 401);
        }

        try {
            $data = json_decode($request->getContent(), true);

            if (isset($data['message'])) {
                $this->handleMessage($data['message']);
            } elseif (isset($data['callback_query'])) {
                $this->handleCallbackQuery($data['callback_query']);
            }

            return new JsonResponse(['ok' => true]);
        } catch (Exception $e) {
            $this->logger->error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'data' => $data ?? null
            ]);

            return new JsonResponse(['ok' => false], 500);
        }
    }

    /**
     * Handle incoming message
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $telegramUserId = $message['from']['id'];
        $username = $message['from']['username'] ?? null;
        $firstName = $message['from']['first_name'] ?? null;
        $lastName = $message['from']['last_name'] ?? null;

        // Find or create user
        $user = $this->userRepository->findOneBy(['telegramUserId' => $telegramUserId]);

        if (!$user) {
            $user = $this->createUser($telegramUserId, $chatId, $username, $firstName, $lastName);
        } else {
            // Update chat ID if changed
            if ($user->getTelegramChatId() !== $chatId) {
                $user->setTelegramChatId($chatId);
                $this->entityManager->flush();
            }
        }

        // Handle commands
        if (str_starts_with($text, '/')) {
            $this->handleCommand($user, $text);
            return;
        }

        // Handle text input based on user state
        $this->handleTextInput($user, $text);
    }

    /**
     * Create new user
     */
    private function createUser(
        int     $telegramUserId,
        int     $chatId,
        ?string $username,
        ?string $firstName,
        ?string $lastName
    ): User
    {
        $user = new User();
        $user->setTelegramUserId($telegramUserId);
        $user->setTelegramChatId($chatId);
        $user->setUsername($username ?? 'user_' . $telegramUserId);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setReferralCode($this->generateReferralCode());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setCreatedAt(new DateTime());

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->logger->info('New user created from Telegram', [
            'user_id' => $user->getId(),
            'telegram_id' => $telegramUserId
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
     * Handle commands
     */
    private function handleCommand(User $user, string $command): void
    {
        $parts = explode(' ', $command);
        $cmd = $parts[0];
        $args = array_slice($parts, 1);

        switch ($cmd) {
            case '/start':
                $this->handleStartCommand($user, $args);
                break;

            case '/menu':
                $this->telegramService->sendMainMenu($user);
                break;

            case '/balance':
                $this->telegramService->sendBalanceInfo($user);
                break;

            case '/deposit':
                $this->telegramService->sendDepositInstructions($user);
                break;

            case '/withdraw':
                $this->handleWithdrawRequest($user);
                break;

            case '/referral':
                $this->telegramService->sendReferralInfo($user);
                break;

            case '/help':
                $this->handleHelpCommand($user);
                break;

            case '/language':
                $this->telegramService->sendLanguageMenu($user);
                break;

            default:
                $this->telegramService->sendMessage(
                    $user->getTelegramChatId(),
                    "Unknown command. Use /help for available commands."
                );
        }
    }

    /**
     * Handle start command
     */
    private function handleStartCommand(User $user, array $args): void
    {
        // Check for referral code
        if (!empty($args[0]) && $user->getReferrer() === null) {
            $referrer = $this->userRepository->findOneBy(['referralCode' => $args[0]]);

            if ($referrer && $referrer->getId() !== $user->getId()) {
                $user->setReferrer($referrer);
                $this->entityManager->flush();

                $this->logger->info('User joined via referral', [
                    'user_id' => $user->getId(),
                    'referrer_id' => $referrer->getId()
                ]);
            }
        }

        $this->telegramService->sendMainMenu($user);
    }

    /**
     * Handle withdrawal request
     */
    private function handleWithdrawRequest(User $user): void
    {
        if ($user->getDepositBalance() < 10) {
            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "❌ Minimum withdrawal amount is 10 USDT. Your current balance: " .
                number_format($user->getDepositBalance(), 2) . " USDT"
            );
            return;
        }

        // Check if deposits can be withdrawn (1 year lock)
        $firstDeposit = $this->entityManager->getRepository(Deposit::class)
            ->findOneBy(['user' => $user], ['createdAt' => 'ASC']);

        if ($firstDeposit) {
            $oneYearAgo = new DateTime('-1 year');
            if ($firstDeposit->getCreatedAt() > $oneYearAgo) {
                $unlockDate = clone $firstDeposit->getCreatedAt();
                $unlockDate->modify('+1 year');

                $this->telegramService->sendMessage(
                    $user->getTelegramChatId(),
                    "❌ Deposits can only be withdrawn after 1 year.\n" .
                    "Unlock date: " . $unlockDate->format('Y-m-d')
                );
                return;
            }
        }

        $user->setState('awaiting_withdrawal_amount');
        $this->entityManager->flush();

        $this->telegramService->sendMessage(
            $user->getTelegramChatId(),
            "💰 Enter withdrawal amount (min 10 USDT):\n" .
            "Available balance: " . number_format($user->getDepositBalance(), 2) . " USDT"
        );
    }

    /**
     * Handle help command
     */
    private function handleHelpCommand(User $user): void
    {
        $message = "🤖 Available commands:\n\n" .
            "/start - Start the bot\n" .
            "/menu - Show main menu\n" .
            "/balance - Check your balance\n" .
            "/deposit - Get deposit instructions\n" .
            "/withdraw - Request withdrawal\n" .
            "/referral - Get referral link\n" .
            "/language - Change language\n" .
            "/help - Show this help message";

        $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
    }

    /**
     * Handle text input based on user state
     */
    private function handleTextInput(User $user, string $text): void
    {
        $state = $user->getState();

        switch ($state) {
            case 'awaiting_withdrawal_amount':
            case 'awaiting_bonus_withdrawal_amount':
                $this->processWithdrawalAmount($user, $text, $state === 'awaiting_bonus_withdrawal_amount' ? 'bonus' : 'deposit');
                break;

            case 'awaiting_withdrawal_address':
                $this->processWithdrawalAddress($user, $text);
                break;

            default:
                $this->telegramService->sendMainMenu($user);
        }
    }

    /**
     * Process withdrawal amount
     */
    private function processWithdrawalAmount(User $user, string $text, string $type): void
    {
        $amount = floatval(str_replace(',', '.', $text));

        if ($amount < 10) {
            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "❌ Minimum withdrawal amount is 10 USDT"
            );
            return;
        }

        $balance = $type === 'bonus' ? $user->getBonusBalance() : $user->getDepositBalance();

        if ($amount > $balance) {
            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "❌ Insufficient balance. Available: " . number_format($balance, 2) . " USDT"
            );
            return;
        }

        $user->setState('awaiting_withdrawal_address');
        $user->setStateData([
            'amount' => $amount,
            'type' => $type
        ]);
        $this->entityManager->flush();

        $this->telegramService->sendMessage(
            $user->getTelegramChatId(),
            "📬 Enter your USDT (TRC20) wallet address:"
        );
    }

    /**
     * Process withdrawal address
     */
    private function processWithdrawalAddress(User $user, string $address): void
    {
        $stateData = $user->getStateData();
        $amount = $stateData['amount'] ?? 0;
        $type = $stateData['type'] ?? 'bonus';

        try {
            $withdrawal = $this->transactionService->createWithdrawal($user, $amount, $address, $type);

            $user->setState(null);
            $user->setStateData(null);
            $this->entityManager->flush();

            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "✅ Withdrawal request created!\n\n" .
                "Amount: " . number_format($amount, 2) . " USDT\n" .
                "Address: " . substr($address, 0, 10) . "..." . substr($address, -10) . "\n" .
                "Status: Pending admin approval\n\n" .
                "You will be notified once processed."
            );
        } catch (Exception $e) {
            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "❌ Error: " . $e->getMessage()
            );
        }
    }

    /**
     * Handle callback query
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'];
        $data = $callbackQuery['data'];
        $telegramUserId = $callbackQuery['from']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $chatId = $callbackQuery['message']['chat']['id'];

        // Find user
        $user = $this->userRepository->findOneBy(['telegramUserId' => $telegramUserId]);
        if (!$user) {
            $this->telegramService->answerCallbackQuery($callbackId, 'Please start the bot first');
            return;
        }

        // Answer callback query immediately
        $this->telegramService->answerCallbackQuery($callbackId);

        // Handle different callbacks
        switch (true) {
            case $data === 'menu':
                $this->telegramService->sendMainMenu($user);
                break;

            case $data === 'balance':
                $this->telegramService->sendBalanceInfo($user);
                break;

            case $data === 'deposit':
                $this->telegramService->sendDepositInstructions($user);
                break;

            case $data === 'withdraw':
                $this->handleWithdrawRequest($user);
                break;

            case $data === 'withdraw_bonus':
                $this->handleBonusWithdrawRequest($user);
                break;

            case $data === 'referrals':
                $this->telegramService->sendReferralInfo($user);
                break;

            case $data === 'language':
                $this->telegramService->sendLanguageMenu($user);
                break;

            case str_starts_with($data, 'lang_'):
                $this->handleLanguageChange($user, substr($data, 5));
                break;

            case $data === 'settings':
                $this->handleSettings($user);
                break;

            case $data === 'history':
                $this->handleTransactionHistory($user);
                break;

            case str_starts_with($data, 'approve_withdrawal_'):
                $this->handleWithdrawalApproval($user, substr($data, 19), true);
                break;

            case str_starts_with($data, 'reject_withdrawal_'):
                $this->handleWithdrawalApproval($user, substr($data, 18), false);
                break;
        }
    }

    /**
     * Handle bonus withdrawal request
     */
    private function handleBonusWithdrawRequest(User $user): void
    {
        if ($user->getBonusBalance() < 10) {
            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "❌ Minimum withdrawal amount is 10 USDT. Your current bonus balance: " .
                number_format($user->getBonusBalance(), 2) . " USDT"
            );
            return;
        }

        $user->setState('awaiting_bonus_withdrawal_amount');
        $this->entityManager->flush();

        $this->telegramService->sendMessage(
            $user->getTelegramChatId(),
            "💎 Enter bonus withdrawal amount (min 10 USDT):\n" .
            "Available bonus balance: " . number_format($user->getBonusBalance(), 2) . " USDT"
        );
    }

    /**
     * Handle language change
     */
    private function handleLanguageChange(User $user, string $locale): void
    {
        $user->setPreferredLocale($locale);
        $this->entityManager->flush();

        $this->telegramService->sendMessage(
            $user->getTelegramChatId(),
            "✅ Language changed successfully!"
        );

        $this->telegramService->sendMainMenu($user);
    }

    /**
     * Handle settings
     */
    private function handleSettings(User $user): void
    {
        $keyboard = [
            [
                ['text' => $user->isNotificationsEnabled() ? '🔔 Notifications: ON' : '🔕 Notifications: OFF',
                    'callback_data' => 'toggle_notifications']
            ],
            [
                ['text' => $user->isTwoFactorEnabled() ? '🔐 2FA: ON' : '🔓 2FA: OFF',
                    'callback_data' => 'toggle_2fa']
            ],
            [
                ['text' => '🔙 Back to menu', 'callback_data' => 'menu']
            ]
        ];

        $this->telegramService->sendMessageWithKeyboard(
            $user->getTelegramChatId(),
            "⚙️ Settings",
            $keyboard
        );
    }

    /**
     * Handle transaction history
     */
    private function handleTransactionHistory(User $user): void
    {
        $transactions = $this->transactionService->getUserTransactionHistory($user, 10);

        if (empty($transactions)) {
            $this->telegramService->sendMessage(
                $user->getTelegramChatId(),
                "📊 No transactions found."
            );
            return;
        }

        $message = "📊 Last 10 transactions:\n\n";

        foreach ($transactions as $tx) {
            $emoji = $tx->getType() === 'deposit' ? '💰' : '📤';
            $sign = $tx->getAmount() > 0 ? '+' : '';

            $message .= sprintf(
                "%s %s%s USDT - %s\n",
                $emoji,
                $sign,
                number_format($tx->getAmount(), 2),
                $tx->getCreatedAt()->format('Y-m-d H:i')
            );
        }

        $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
    }

    /**
     * Handle withdrawal approval (for admins)
     */
    private function handleWithdrawalApproval(User $admin, string $withdrawalId, bool $approve): void
    {
        if (!in_array('ROLE_ADMIN', $admin->getRoles())) {
            return;
        }

        // Implementation depends on withdrawal service
        // This is a placeholder
        $message = $approve
            ? "✅ Withdrawal approved by " . $admin->getUsername()
            : "❌ Withdrawal rejected by " . $admin->getUsername();

        $this->telegramService->sendMessage($admin->getTelegramChatId(), $message);
    }
}