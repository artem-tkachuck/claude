<?php

namespace App\Controller\Admin;

use App\Repository\BonusRepository;
use App\Repository\DepositRepository;
use App\Repository\EventLogRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Repository\WithdrawalRepository;
use App\Service\Notification\NotificationService;
use DateTime;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    private UserRepository $userRepository;
    private DepositRepository $depositRepository;
    private WithdrawalRepository $withdrawalRepository;
    private TransactionRepository $transactionRepository;
    private BonusRepository $bonusRepository;
    private EventLogRepository $eventLogRepository;
    private NotificationService $notificationService;

    public function __construct(
        UserRepository        $userRepository,
        DepositRepository     $depositRepository,
        WithdrawalRepository  $withdrawalRepository,
        TransactionRepository $transactionRepository,
        BonusRepository       $bonusRepository,
        EventLogRepository    $eventLogRepository,
        NotificationService   $notificationService
    )
    {
        $this->userRepository = $userRepository;
        $this->depositRepository = $depositRepository;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->transactionRepository = $transactionRepository;
        $this->bonusRepository = $bonusRepository;
        $this->eventLogRepository = $eventLogRepository;
        $this->notificationService = $notificationService;
    }

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
// Get current statistics
        $stats = $this->getStatistics();

// Get recent activities
        $recentDeposits = $this->depositRepository->findBy([], ['createdAt' => 'DESC'], 10);
        $pendingWithdrawals = $this->withdrawalRepository->findBy(['status' => 'pending'], ['createdAt' => 'DESC']);
        $recentEvents = $this->eventLogRepository->findCriticalEvents(new DateTime('-24 hours'), 20);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recent_deposits' => $recentDeposits,
            'pending_withdrawals' => $pendingWithdrawals,
            'recent_events' => $recentEvents
        ]);
    }

    /**
     * Get dashboard statistics
     */
    private function getStatistics(): array
    {
        $now = new DateTime();
        $today = new DateTime('today');
        $weekAgo = new DateTime('-7 days');
        $monthAgo = new DateTime('-30 days');

        return [
            'users' => [
                'total' => $this->userRepository->count([]),
                'active' => $this->userRepository->countActiveUsers(),
                'new_today' => $this->userRepository->countNewUsers($today),
                'new_week' => $this->userRepository->countNewUsers($weekAgo)
            ],
            'deposits' => [
                'total' => $this->depositRepository->getTotalAmount(),
                'today' => $this->depositRepository->getTotalAmount($today),
                'week' => $this->depositRepository->getTotalAmount($weekAgo),
                'month' => $this->depositRepository->getTotalAmount($monthAgo),
                'pending' => $this->depositRepository->count(['status' => 'pending'])
            ],
            'withdrawals' => [
                'total' => $this->withdrawalRepository->getTotalAmount(),
                'today' => $this->withdrawalRepository->getTotalAmount($today),
                'week' => $this->withdrawalRepository->getTotalAmount($weekAgo),
                'pending' => $this->withdrawalRepository->count(['status' => 'pending'])
            ],
            'bonuses' => [
                'total_distributed' => $this->bonusRepository->getTotalDistributed(),
                'today' => $this->bonusRepository->getTodayTotal(),
                'week' => $this->bonusRepository->getWeekTotal()
            ],
            'balance' => [
                'total_deposits' => $this->userRepository->getTotalDepositsBalance(),
                'total_bonuses' => $this->userRepository->getTotalBonusesBalance(),
                'hot_wallet' => $this->tronService->getBalance($_ENV['HOT_WALLET_ADDRESS'] ?? '')
            ]
        ];
    }

    #[Route('/users', name: 'users')]
    public function users(): Response
    {
        $users = $this->userRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users
        ]);
    }

    #[Route('/user/{id}', name: 'user_detail')]
    public function userDetail(int $id): Response
    {
        $user = $this->userRepository->find($id);

        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }

        $userStats = [
            'total_deposits' => $this->depositRepository->getUserTotalDeposits($user),
            'total_withdrawals' => $this->withdrawalRepository->getUserTotalWithdrawals($user),
            'total_bonuses' => $this->bonusRepository->getUserTotalBonuses($user),
            'referral_count' => count($this->userRepository->findBy(['referrer' => $user])),
            'transactions' => $this->transactionRepository->findBy(['user' => $user], ['createdAt' => 'DESC'], 20)
        ];

        return $this->render('admin/user_detail.html.twig', [
            'user' => $user,
            'stats' => $userStats
        ]);
    }

    #[Route('/deposits', name: 'deposits')]
    public function deposits(): Response
    {
        $deposits = $this->depositRepository->findBy([], ['createdAt' => 'DESC'], 100);

        return $this->render('admin/deposits.html.twig', [
            'deposits' => $deposits
        ]);
    }

    #[Route('/withdrawals', name: 'withdrawals')]
    public function withdrawals(): Response
    {
        $withdrawals = $this->withdrawalRepository->findBy([], ['createdAt' => 'DESC'], 100);

        $pendingCount = $this->withdrawalRepository->count(['status' => 'pending']);

        return $this->render('admin/withdrawals.html.twig', [
            'withdrawals' => $withdrawals,
            'pending_count' => $pendingCount
        ]);
    }

    #[Route('/withdrawal/{id}/approve', name: 'withdrawal_approve', methods: ['POST'])]
    public function approveWithdrawal(int $id): Response
    {
        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal || $withdrawal->getStatus() !== 'pending') {
            $this->addFlash('error', 'Invalid withdrawal or already processed');
            return $this->redirectToRoute('admin_withdrawals');
        }

        try {
            $admin = $this->getUser();
            $this->transactionService->processWithdrawal($withdrawal, $admin);

            $this->addFlash('success', 'Withdrawal approved');
        } catch (Exception $e) {
            $this->addFlash('error', 'Failed to approve withdrawal: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_withdrawals');
    }

    #[Route('/withdrawal/{id}/reject', name: 'withdrawal_reject', methods: ['POST'])]
    public function rejectWithdrawal(int $id): Response
    {
        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal || $withdrawal->getStatus() !== 'pending') {
            $this->addFlash('error', 'Invalid withdrawal or already processed');
            return $this->redirectToRoute('admin_withdrawals');
        }

        try {
            $reason = $this->request->request->get('reason', 'Rejected by admin');
            $this->transactionService->cancelWithdrawal($withdrawal, $reason);

            $this->addFlash('success', 'Withdrawal rejected');
        } catch (Exception $e) {
            $this->addFlash('error', 'Failed to reject withdrawal: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_withdrawals');
    }

    #[Route('/bonuses', name: 'bonuses')]
    public function bonuses(): Response
    {
        $bonuses = $this->bonusRepository->findBy([], ['createdAt' => 'DESC'], 100);

        $stats = [
            'today' => $this->bonusRepository->getTodayTotal(),
            'week' => $this->bonusRepository->getWeekTotal(),
            'month' => $this->bonusRepository->getMonthTotal()
        ];

        return $this->render('admin/bonuses.html.twig', [
            'bonuses' => $bonuses,
            'stats' => $stats
        ]);
    }

    #[Route('/distribute-bonus', name: 'distribute_bonus', methods: ['POST'])]
    public function distributeBonus(): Response
    {
        $amount = $this->request->request->get('amount');

        if (!$amount || $amount <= 0) {
            $this->addFlash('error', 'Invalid amount');
            return $this->redirectToRoute('admin_bonuses');
        }

        try {
            $result = $this->bonusCalculator->calculateDailyBonuses($amount);

            $this->addFlash('success', sprintf(
                'Bonuses distributed successfully. Total: %s USDT to %d users',
                number_format($result['distributed'], 2),
                $result['users_count']
            ));
        } catch (Exception $e) {
            $this->addFlash('error', 'Failed to distribute bonuses: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_bonuses');
    }

    #[Route('/events', name: 'events')]
    public function events(): Response
    {
        $events = $this->eventLogRepository->findBy([], ['createdAt' => 'DESC'], 500);

        $criticalEvents = $this->eventLogRepository->findCriticalEvents(new DateTime('-7 days'), 100);

        return $this->render('admin/events.html.twig', [
            'events' => $events,
            'critical_events' => $criticalEvents
        ]);
    }

    #[Route('/settings', name: 'settings')]
    public function settings(): Response
    {
        return $this->render('admin/settings.html.twig');
    }

    #[Route('/reports', name: 'reports')]
    public function reports(): Response
    {
        $dateFrom = new DateTime($this->request->query->get('from', '-30 days'));
        $dateTo = new DateTime($this->request->query->get('to', 'now'));

        $reports = [
            'deposits' => $this->depositRepository->getReportData($dateFrom, $dateTo),
            'withdrawals' => $this->withdrawalRepository->getReportData($dateFrom, $dateTo),
            'bonuses' => $this->bonusRepository->getReportData($dateFrom, $dateTo),
            'users' => $this->userRepository->getReportData($dateFrom, $dateTo)
        ];

        return $this->render('admin/reports.html.twig', [
            'reports' => $reports,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    }

    #[Route('/broadcast', name: 'broadcast', methods: ['GET', 'POST'])]
    public function broadcast(): Response
    {
        if ($this->request->isMethod('POST')) {
            $message = $this->request->request->get('message');
            $channel = $this->request->request->get('channel', 'telegram'); // telegram, email, all

            if (!$message) {
                $this->addFlash('error', 'Message cannot be empty');
                return $this->redirectToRoute('admin_broadcast');
            }

            try {
                $users = $this->userRepository->findActiveUsers();
                $sent = 0;

                foreach ($users as $user) {
                    if ($channel === 'telegram' || $channel === 'all') {
                        if ($user->getTelegramChatId()) {
                            $this->telegramService->sendMessage($user->getTelegramChatId(), $message);
                            $sent++;
                        }
                    }

                    if ($channel === 'email' || $channel === 'all') {
                        if ($user->getEmail()) {
// Send email broadcast
                            $sent++;
                        }
                    }
                }

                $this->addFlash('success', sprintf('Message sent to %d users', $sent));
            } catch (Exception $e) {
                $this->addFlash('error', 'Failed to send broadcast: ' . $e->getMessage());
            }

            return $this->redirectToRoute('admin_broadcast');
        }

        return $this->render('admin/broadcast.html.twig');
    }

    #[Route('/export/{type}', name: 'export')]
    public function export(string $type): Response
    {
        $data = [];
        $filename = '';

        switch ($type) {
            case 'users':
                $data = $this->userRepository->findAll();
                $filename = 'users_' . date('Y-m-d') . '.csv';
                break;
            case 'deposits':
                $data = $this->depositRepository->findAll();
                $filename = 'deposits_' . date('Y-m-d') . '.csv';
                break;
            case 'withdrawals':
                $data = $this->withdrawalRepository->findAll();
                $filename = 'withdrawals_' . date('Y-m-d') . '.csv';
                break;
            default:
                throw $this->createNotFoundException('Invalid export type');
        }

// Generate CSV content
        $csv = $this->generateCsv($data, $type);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * Generate CSV from data
     */
    private function generateCsv(array $data, string $type): string
    {
        $csv = '';

        if (empty($data)) {
            return $csv;
        }

// Headers based on type
        switch ($type) {
            case 'users':
                $csv = "ID,Username,Email,Telegram ID,Deposit Balance,Bonus Balance,Referral Code,Created At\n";
                foreach ($data as $user) {
                    $csv .= sprintf(
                        "%d,%s,%s,%s,%.2f,%.2f,%s,%s\n",
                        $user->getId(),
                        $user->getUsername(),
                        $user->getEmail() ?? '',
                        $user->getTelegramUserId() ?? '',
                        $user->getDepositBalance(),
                        $user->getBonusBalance(),
                        $user->getReferralCode(),
                        $user->getCreatedAt()->format('Y-m-d H:i:s')
                    );
                }
                break;

            case 'deposits':
                $csv = "ID,User,Amount,TX Hash,Status,Created At,Confirmed At\n";
                foreach ($data as $deposit) {
                    $csv .= sprintf(
                        "%d,%s,%.2f,%s,%s,%s,%s\n",
                        $deposit->getId(),
                        $deposit->getUser()->getUsername(),
                        $deposit->getAmount(),
                        $deposit->getTxHash(),
                        $deposit->getStatus(),
                        $deposit->getCreatedAt()->format('Y-m-d H:i:s'),
                        $deposit->getConfirmedAt() ? $deposit->getConfirmedAt()->format('Y-m-d H:i:s') : ''
                    );
                }
                break;

            case 'withdrawals':
                $csv = "ID,User,Amount,Address,Type,Status,TX Hash,Created At,Processed At\n";
                foreach ($data as $withdrawal) {
                    $csv .= sprintf(
                        "%d,%s,%.2f,%s,%s,%s,%s,%s,%s\n",
                        $withdrawal->getId(),
                        $withdrawal->getUser()->getUsername(),
                        $withdrawal->getAmount(),
                        $withdrawal->getAddress(),
                        $withdrawal->getType(),
                        $withdrawal->getStatus(),
                        $withdrawal->getTxHash() ?? '',
                        $withdrawal->getCreatedAt()->format('Y-m-d H:i:s'),
                        $withdrawal->getProcessedAt() ? $withdrawal->getProcessedAt()->format('Y-m-d H:i:s') : ''
                    );
                }
                break;
        }

        return $csv;
    }
}