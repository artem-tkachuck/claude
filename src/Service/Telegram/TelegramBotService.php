<?php

namespace App\Service\Telegram;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\EncryptionService;
use Exception;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TelegramBotService
{
    private Telegram $telegram;
    private UserRepository $userRepository;
    private TranslatorInterface $translator;
    private EncryptionService $encryption;
    private LoggerInterface $logger;
    private MenuBuilder $menuBuilder;
    private array $commandHandlers;

    public function __construct(
        string              $botToken,
        string              $botUsername,
        UserRepository      $userRepository,
        TranslatorInterface $translator,
        EncryptionService   $encryption,
        LoggerInterface     $logger,
        MenuBuilder         $menuBuilder,
        iterable            $commandHandlers
    )
    {
        $this->telegram = new Telegram($botToken, $botUsername);
        $this->userRepository = $userRepository;
        $this->translator = $translator;
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->menuBuilder = $menuBuilder;
        $this->commandHandlers = iterator_to_array($commandHandlers);
    }

    public function processUpdate(Update $update): void
    {
        try {
            $message = $update->getMessage() ?? $update->getCallbackQuery()?->getMessage();

            if (!$message) {
                return;
            }

            $telegramId = $message->getFrom()->getId();
            $user = $this->userRepository->findOneBy(['telegramId' => $telegramId]);

            if (!$user && !$this->isStartCommand($update)) {
                $this->sendUnauthorizedMessage($telegramId);
                return;
            }

            // Process callback queries
            if ($callbackQuery = $update->getCallbackQuery()) {
                $this->processCallbackQuery($callbackQuery, $user);
                return;
            }

            // Process commands
            if ($text = $message->getText()) {
                $this->processCommand($text, $user, $message);
            }
        } catch (Exception $e) {
            $this->logger->error('Telegram update processing failed', [
                'update_id' => $update->getUpdateId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isStartCommand(Update $update): bool
    {
        $text = $update->getMessage()?->getText();
        return $text && str_starts_with($text, '/start');
    }

    private function sendUnauthorizedMessage(int $chatId): void
    {
        $this->sendMessage($chatId, $this->translator->trans('telegram.unauthorized'));
    }

    public function sendMessage(int $chatId, string $text, ?InlineKeyboard $keyboard = null): void
    {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }

        Request::sendMessage($data);
    }

    private function processCallbackQuery($callbackQuery, ?User $user): void
    {
        $data = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        // Answer callback to remove loading state
        Request::answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
        ]);

        // Route to appropriate handler based on callback data
        [$action, $params] = explode(':', $data, 2) + [null, null];

        switch ($action) {
            case 'language':
                $this->handleLanguageChange($user, $params, $chatId);
                break;
            case 'balance':
                $this->handleBalanceRequest($user, $chatId);
                break;
            case 'deposit':
                $this->handleDepositRequest($user, $chatId);
                break;
            case 'withdraw':
                $this->handleWithdrawRequest($user, $chatId);
                break;
            case 'referral':
                $this->handleReferralInfo($user, $chatId);
                break;
        }
    }

    private function handleLanguageChange(User $user, string $language, int $chatId): void
    {
        if (in_array($language, ['en', 'uk', 'ru'])) {
            $user->setLanguage($language);
            $this->userRepository->save($user, true);

            $this->sendMessage(
                $chatId,
                $this->translator->trans('telegram.language_changed', [], null, $language)
            );

            $this->sendMainMenu($user);
        }
    }

    public function sendMainMenu(User $user): void
    {
        $keyboard = $this->menuBuilder->buildMainMenu($user);

        $this->sendMessage(
            $user->getTelegramId(),
            $this->translator->trans('telegram.main_menu', [], null, $user->getLanguage()),
            $keyboard
        );
    }

    private function handleBalanceRequest(User $user, int $chatId): void
    {
        $message = $this->translator->trans('telegram.balance_info', [
            'deposit' => $user->getDepositBalance(),
            'bonus' => $user->getBonusBalance(),
            'referral' => $user->getReferralBalance(),
            'total' => $user->getTotalBalance(),
            'available' => $user->getAvailableForWithdrawal(),
        ], null, $user->getLanguage());

        $this->sendMessage($chatId, $message);
    }

    private function processCommand(string $text, ?User $user, $message): void
    {
        $command = explode(' ', $text)[0];
        $command = str_replace('/', '', $command);

        if (isset($this->commandHandlers[$command])) {
            $handler = $this->commandHandlers[$command];
            $handler->handle($message, $user, $this);
        } else {
            $this->sendMessage($message->getChat()->getId(),
                $this->translator->trans('telegram.unknown_command'));
        }
    }

    public function sendAdminNotification(string $message, array $data = []): void
    {
        $admins = $this->userRepository->findByRole('ROLE_ADMIN');

        foreach ($admins as $admin) {
            try {
                $this->sendMessage(
                    $admin->getTelegramId(),
                    $this->translator->trans($message, $data, null, $admin->getLanguage())
                );
            } catch (Exception $e) {
                $this->logger->error('Failed to send admin notification', [
                    'admin_id' => $admin->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function generateAuthToken(User $user): string
    {
        $payload = [
            'user_id' => $user->getId(),
            'telegram_id' => $user->getTelegramId(),
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $token = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $token, $_ENV['APP_SECRET']);

        return $token . '.' . $signature;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $_ENV['TELEGRAM_WEBHOOK_SECRET']);
        return hash_equals($expected, $signature);
    }
}