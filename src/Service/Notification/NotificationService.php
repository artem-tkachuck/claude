<?php

namespace App\Service\Notification;

use App\Entity\Deposit;
use App\Entity\User;
use App\Entity\Withdrawal;
use App\Repository\UserRepository;
use App\Service\Telegram\TelegramBotService;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class NotificationService
{
    private TelegramBotService $telegramService;
    private MailerInterface $mailer;
    private UserRepository $userRepository;
    private TranslatorInterface $translator;
    private Environment $twig;
    private LoggerInterface $logger;
    private string $fromEmail;

    public function __construct(
        TelegramBotService  $telegramService,
        MailerInterface     $mailer,
        UserRepository      $userRepository,
        TranslatorInterface $translator,
        Environment         $twig,
        LoggerInterface     $logger,
        string              $fromEmail
    )
    {
        $this->telegramService = $telegramService;
        $this->mailer = $mailer;
        $this->userRepository = $userRepository;
        $this->translator = $translator;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->fromEmail = $fromEmail;
    }

    /**
     * Notify user about confirmed deposit
     */
    public function notifyDepositConfirmed(User $user, Deposit $deposit): void
    {
        $this->translator->setLocale($user->getPreferredLocale());

        // Telegram notification
        if ($user->getTelegramChatId() && $user->isNotificationsEnabled()) {
            $message = $this->translator->trans('notification.deposit.confirmed', [
                '%amount%' => number_format($deposit->getAmount(), 2),
                '%txid%' => substr($deposit->getTxHash(), 0, 10) . '...',
                '%balance%' => number_format($user->getDepositBalance(), 2)
            ]);

            $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
        }

        // Email notification
        if ($user->getEmail() && $user->isEmailNotificationsEnabled()) {
            $this->sendEmail(
                $user,
                'notification.deposit.confirmed.subject',
                'email/deposit_confirmed.html.twig',
                [
                    'user' => $user,
                    'deposit' => $deposit
                ]
            );
        }

        $this->logger->info('Deposit confirmation notification sent', [
            'user_id' => $user->getId(),
            'deposit_id' => $deposit->getId()
        ]);
    }

    /**
     * Send email
     */
    private function sendEmail(User $user, string $subjectKey, string $template, array $context = []): void
    {
        try {
            $subject = $this->translator->trans($subjectKey, [], null, $user->getPreferredLocale());

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($user->getEmail())
                ->subject($subject)
                ->html($this->twig->render($template, array_merge($context, [
                    'locale' => $user->getPreferredLocale()
                ])));

            $this->mailer->send($email);

            $this->logger->info('Email sent', [
                'user_id' => $user->getId(),
                'template' => $template
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify admins about new deposit
     */
    public function notifyAdminsNewDeposit(Deposit $deposit): void
    {
        $message = sprintf(
            "💰 New deposit received!\n\n" .
            "User: %s (ID: %d)\n" .
            "Amount: %s USDT\n" .
            "TX: %s\n" .
            "Status: Confirmed ✅",
            $deposit->getUser()->getUsername(),
            $deposit->getUser()->getId(),
            number_format($deposit->getAmount(), 2),
            $deposit->getTxHash()
        );

        $this->telegramService->notifyAdmins($message);
    }

    /**
     * Notify user about withdrawal completion
     */
    public function notifyWithdrawalCompleted(User $user, Withdrawal $withdrawal): void
    {
        $this->translator->setLocale($user->getPreferredLocale());

        // Telegram notification
        if ($user->getTelegramChatId() && $user->isNotificationsEnabled()) {
            $message = $this->translator->trans('notification.withdrawal.completed', [
                '%amount%' => number_format($withdrawal->getAmount(), 2),
                '%address%' => substr($withdrawal->getAddress(), 0, 10) . '...',
                '%txid%' => substr($withdrawal->getTxHash(), 0, 10) . '...'
            ]);

            $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
        }

        // Email notification
        if ($user->getEmail() && $user->isEmailNotificationsEnabled()) {
            $this->sendEmail(
                $user,
                'notification.withdrawal.completed.subject',
                'email/withdrawal_completed.html.twig',
                [
                    'user' => $user,
                    'withdrawal' => $withdrawal
                ]
            );
        }
    }

    /**
     * Notify admins about new withdrawal request
     */
    public function notifyAdminsNewWithdrawal(Withdrawal $withdrawal): void
    {
        $message = sprintf(
            "📤 New withdrawal request!\n\n" .
            "User: %s (ID: %d)\n" .
            "Amount: %s USDT\n" .
            "Type: %s\n" .
            "Address: %s\n\n" .
            "⚠️ Requires approval from 2 admins",
            $withdrawal->getUser()->getUsername(),
            $withdrawal->getUser()->getId(),
            number_format($withdrawal->getAmount(), 2),
            ucfirst($withdrawal->getType()),
            $withdrawal->getAddress()
        );

        $keyboard = [
            [
                [
                    'text' => '✅ Approve',
                    'callback_data' => 'approve_withdrawal_' . $withdrawal->getId()
                ],
                [
                    'text' => '❌ Reject',
                    'callback_data' => 'reject_withdrawal_' . $withdrawal->getId()
                ]
            ]
        ];

        $admins = $this->userRepository->findAdmins();
        foreach ($admins as $admin) {
            if ($admin->getTelegramChatId() && $admin->isNotificationsEnabled()) {
                $this->telegramService->sendMessageWithKeyboard(
                    $admin->getTelegramChatId(),
                    $message,
                    $keyboard
                );
            }
        }
    }

    /**
     * Notify user about bonus
     */
    public function notifyUserBonus(User $user, float $amount, string $type): void
    {
        $this->translator->setLocale($user->getPreferredLocale());

        if ($user->getTelegramChatId() && $user->isNotificationsEnabled()) {
            $message = $this->translator->trans('notification.bonus.' . $type, [
                '%amount%' => number_format($amount, 2),
                '%balance%' => number_format($user->getBonusBalance(), 2)
            ]);

            $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
        }
    }

    /**
     * Notify about withdrawal cancellation
     */
    public function notifyWithdrawalCancelled(User $user, Withdrawal $withdrawal): void
    {
        $this->translator->setLocale($user->getPreferredLocale());

        if ($user->getTelegramChatId() && $user->isNotificationsEnabled()) {
            $message = $this->translator->trans('notification.withdrawal.cancelled', [
                '%amount%' => number_format($withdrawal->getAmount(), 2),
                '%reason%' => $withdrawal->getFailureReason()
            ]);

            $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
        }
    }

    /**
     * Send security alert
     */
    public function sendSecurityAlert(User $user, string $alertType, array $details = []): void
    {
        $this->translator->setLocale($user->getPreferredLocale());

        $message = $this->translator->trans('notification.security.' . $alertType, $details);

        // Always send security alerts regardless of notification preferences
        if ($user->getTelegramChatId()) {
            $this->telegramService->sendMessage($user->getTelegramChatId(), '🔐 ' . $message);
        }

        if ($user->getEmail()) {
            $this->sendEmail(
                $user,
                'notification.security.alert.subject',
                'email/security_alert.html.twig',
                [
                    'user' => $user,
                    'alert_type' => $alertType,
                    'details' => $details,
                    'message' => $message
                ]
            );
        }

        $this->logger->warning('Security alert sent', [
            'user_id' => $user->getId(),
            'alert_type' => $alertType,
            'details' => $details
        ]);
    }

    /**
     * Send daily summary to admins
     */
    public function sendDailySummary(array $stats): void
    {
        $message = sprintf(
            "📊 Daily Summary\n\n" .
            "💰 Deposits: %d (%s USDT)\n" .
            "📤 Withdrawals: %d (%s USDT)\n" .
            "💎 Bonuses distributed: %s USDT\n" .
            "👥 New users: %d\n" .
            "📈 Total balance: %s USDT\n\n" .
            "Generated at: %s",
            $stats['deposits_count'],
            number_format($stats['deposits_amount'], 2),
            $stats['withdrawals_count'],
            number_format($stats['withdrawals_amount'], 2),
            number_format($stats['bonuses_amount'], 2),
            $stats['new_users'],
            number_format($stats['total_balance'], 2),
            (new DateTime())->format('Y-m-d H:i:s')
        );

        $this->telegramService->notifyAdmins($message);
    }

    /**
     * Notify about system maintenance
     */
    public function notifySystemMaintenance(DateTime $startTime, int $duration): void
    {
        $users = $this->userRepository->findActiveUsers();

        foreach ($users as $user) {
            $this->translator->setLocale($user->getPreferredLocale());

            $message = $this->translator->trans('notification.maintenance', [
                '%start_time%' => $startTime->format('Y-m-d H:i'),
                '%duration%' => $duration
            ]);

            if ($user->getTelegramChatId() && $user->isNotificationsEnabled()) {
                $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
            }
        }
    }
}
