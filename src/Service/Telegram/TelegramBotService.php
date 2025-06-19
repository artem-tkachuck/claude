<?php

namespace App\Service\Telegram;

use App\Entity\User;
use App\Repository\UserRepository;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class TelegramBotService
{
    private HttpClientInterface $httpClient;
    private UserRepository $userRepository;
    private TranslatorInterface $translator;
    private UrlGeneratorInterface $urlGenerator;
    private LoggerInterface $logger;
    private string $botToken;
    private string $botUsername;
    private string $apiUrl;

    public function __construct(
        HttpClientInterface                                $httpClient,
        UserRepository                                     $userRepository,
        TranslatorInterface                                $translator,
        UrlGeneratorInterface                              $urlGenerator,
        LoggerInterface                                    $logger,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')] string    $botToken,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')] string $botUsername
    )
    {
        $this->httpClient = $httpClient;
        $this->userRepository = $userRepository;
        $this->translator = $translator;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->botToken = $botToken;
        $this->botUsername = $botUsername;
        $this->apiUrl = "https://api.telegram.org/bot{$botToken}/";
    }

    /**
     * Send main menu
     */
    public function sendMainMenu(User $user): bool
    {
        $locale = $user->getPreferredLocale();
        $this->translator->setLocale($locale);

        $text = $this->translator->trans('telegram.menu.welcome', [
            '%name%' => $user->getFirstName() ?? $user->getUsername()
        ]);

        $keyboard = [
            [
                ['text' => '💰 ' . $this->translator->trans('telegram.menu.balance'), 'callback_data' => 'balance'],
                ['text' => '📈 ' . $this->translator->trans('telegram.menu.deposit'), 'callback_data' => 'deposit']
            ],
            [
                ['text' => '📤 ' . $this->translator->trans('telegram.menu.withdraw'), 'callback_data' => 'withdraw'],
                ['text' => '💎 ' . $this->translator->trans('telegram.menu.bonus'), 'callback_data' => 'bonus']
            ],
            [
                ['text' => '👥 ' . $this->translator->trans('telegram.menu.referrals'), 'callback_data' => 'referrals'],
                ['text' => '📊 ' . $this->translator->trans('telegram.menu.history'), 'callback_data' => 'history']
            ],
            [
                ['text' => '⚙️ ' . $this->translator->trans('telegram.menu.settings'), 'callback_data' => 'settings'],
                ['text' => '🌐 ' . $this->translator->trans('telegram.menu.language'), 'callback_data' => 'language']
            ]
        ];

        return $this->sendMessageWithKeyboard($user->getTelegramChatId(), $text, $keyboard);
    }

    /**
     * Send message with inline keyboard
     */
    public function sendMessageWithKeyboard(int $chatId, string $text, array $keyboard): bool
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => [
                'inline_keyboard' => $keyboard
            ]
        ]);
    }

    /**
     * Send message to user
     */
    public function sendMessage(int $chatId, string $text, array $options = []): bool
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ], $options);

            $response = $this->httpClient->request('POST', $this->apiUrl . 'sendMessage', [
                'json' => $params
            ]);

            $result = $response->toArray();

            if (!$result['ok']) {
                throw new RuntimeException($result['description'] ?? 'Unknown error');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to send Telegram message', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send balance info
     */
    public function sendBalanceInfo(User $user): bool
    {
        $locale = $user->getPreferredLocale();
        $this->translator->setLocale($locale);

        $text = $this->translator->trans('telegram.balance.info', [
            '%deposit%' => number_format($user->getDepositBalance(), 2),
            '%bonus%' => number_format($user->getBonusBalance(), 2),
            '%total%' => number_format($user->getTotalBalance(), 2),
            '%currency%' => 'USDT'
        ]);

        $keyboard = [
            [
                ['text' => '📈 ' . $this->translator->trans('telegram.button.deposit'), 'callback_data' => 'deposit'],
                ['text' => '📤 ' . $this->translator->trans('telegram.button.withdraw'), 'callback_data' => 'withdraw_bonus']
            ],
            [
                ['text' => '🔙 ' . $this->translator->trans('telegram.button.back'), 'callback_data' => 'menu']
            ]
        ];

        return $this->sendMessageWithKeyboard($user->getTelegramChatId(), $text, $keyboard);
    }

    /**
     * Send deposit instructions
     */
    public function sendDepositInstructions(User $user): bool
    {
        $locale = $user->getPreferredLocale();
        $this->translator->setLocale($locale);

        $depositAddress = $user->getDepositAddress();
        if (!$depositAddress) {
            return $this->sendMessage(
                $user->getTelegramChatId(),
                $this->translator->trans('telegram.error.no_deposit_address')
            );
        }

        $text = $this->translator->trans('telegram.deposit.instructions', [
            '%address%' => $depositAddress,
            '%min%' => '100',
            '%currency%' => 'USDT TRC20'
        ]);

        $keyboard = [
            [
                ['text' => '📋 ' . $this->translator->trans('telegram.button.copy_address'), 'copy_text' => ['text' => $depositAddress]]
            ],
            [
                ['text' => '🔙 ' . $this->translator->trans('telegram.button.back'), 'callback_data' => 'menu']
            ]
        ];

        // Send QR code
        $this->sendQrCode($user->getTelegramChatId(), $depositAddress);

        return $this->sendMessageWithKeyboard($user->getTelegramChatId(), $text, $keyboard);
    }

    /**
     * Send QR code
     */
    public function sendQrCode(int $chatId, string $data): bool
    {
        try {
            $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($data);

            $response = $this->httpClient->request('POST', $this->apiUrl . 'sendPhoto', [
                'json' => [
                    'chat_id' => $chatId,
                    'photo' => $qrApiUrl,
                    'caption' => 'QR Code'
                ]
            ]);

            $result = $response->toArray();
            return $result['ok'] ?? false;
        } catch (Exception $e) {
            $this->logger->error('Failed to send QR code', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send referral info
     */
    public function sendReferralInfo(User $user): bool
    {
        $locale = $user->getPreferredLocale();
        $this->translator->setLocale($locale);

        $referralLink = "https://t.me/{$this->botUsername}?start={$user->getReferralCode()}";
        $referralStats = $this->userRepository->getReferralStats($user);

        $text = $this->translator->trans('telegram.referral.info', [
            '%link%' => $referralLink,
            '%count%' => $referralStats['total_referrals'],
            '%level1%' => $referralStats['level1_count'],
            '%level2%' => $referralStats['level2_count'],
            '%earnings%' => number_format($referralStats['total_earnings'], 2)
        ]);

        $keyboard = [
            [
                ['text' => '📋 ' . $this->translator->trans('telegram.button.copy_link'), 'copy_text' => ['text' => $referralLink]]
            ],
            [
                ['text' => '📊 ' . $this->translator->trans('telegram.button.referral_stats'), 'callback_data' => 'referral_stats']
            ],
            [
                ['text' => '🔙 ' . $this->translator->trans('telegram.button.back'), 'callback_data' => 'menu']
            ]
        ];

        return $this->sendMessageWithKeyboard($user->getTelegramChatId(), $text, $keyboard);
    }

    /**
     * Send language selection menu
     */
    public function sendLanguageMenu(User $user): bool
    {
        $text = "🌐 Select your language / Виберіть мову / Выберите язык:";

        $keyboard = [
            [
                ['text' => '🇬🇧 English', 'callback_data' => 'lang_en'],
                ['text' => '🇺🇦 Українська', 'callback_data' => 'lang_uk']
            ],
            [
                ['text' => '🇷🇺 Русский', 'callback_data' => 'lang_ru']
            ],
            [
                ['text' => '🔙 Back', 'callback_data' => 'menu']
            ]
        ];

        return $this->sendMessageWithKeyboard($user->getTelegramChatId(), $text, $keyboard);
    }

    /**
     * Send notification to admins
     */
    public function notifyAdmins(string $message, array $options = []): void
    {
        $admins = $this->userRepository->findAdmins();

        foreach ($admins as $admin) {
            if ($admin->getTelegramChatId() && $admin->isNotificationsEnabled()) {
                $this->sendMessage($admin->getTelegramChatId(), $message, $options);
            }
        }

        $this->logger->info('Admin notification sent', [
            'message' => $message,
            'admin_count' => count($admins)
        ]);
    }

    /**
     * Edit message
     */
    public function editMessage(int $chatId, int $messageId, string $text, array $options = []): bool
    {
        try {
            $params = array_merge([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML'
            ], $options);

            $response = $this->httpClient->request('POST', $this->apiUrl . 'editMessageText', [
                'json' => $params
            ]);

            $result = $response->toArray();
            return $result['ok'] ?? false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Answer callback query
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . 'answerCallbackQuery', [
                'json' => [
                    'callback_query_id' => $callbackQueryId,
                    'text' => $text,
                    'show_alert' => $showAlert
                ]
            ]);

            $result = $response->toArray();
            return $result['ok'] ?? false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Set webhook
     */
    public function setWebhook(string $url, string $secretToken): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . 'setWebhook', [
                'json' => [
                    'url' => $url,
                    'allowed_updates' => ['message', 'callback_query'],
                    'drop_pending_updates' => true,
                    'secret_token' => $secretToken
                ]
            ]);

            $result = $response->toArray();

            if ($result['ok']) {
                $this->logger->info('Webhook set successfully', ['url' => $url]);
                return true;
            }

            throw new RuntimeException($result['description'] ?? 'Failed to set webhook');
        } catch (Exception $e) {
            $this->logger->error('Failed to set webhook', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook(): bool
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . 'deleteWebhook');
            $result = $response->toArray();
            return $result['ok'] ?? false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get webhook info
     */
    public function getWebhookInfo(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . 'getWebhookInfo');
            return $response->toArray()['result'] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }
}
