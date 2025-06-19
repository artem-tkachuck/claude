<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\WithdrawalRepository;
use App\Service\Transaction\TransactionService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/withdrawal', name: 'admin_withdrawal_')]
#[IsGranted('ROLE_ADMIN')]
class WithdrawalController extends AbstractController
{
    private WithdrawalRepository $withdrawalRepository;
    private TransactionService $transactionService;
    private LoggerInterface $logger;

    public function __construct(
        WithdrawalRepository $withdrawalRepository,
        TransactionService   $transactionService,
        LoggerInterface      $logger
    )
    {
        $this->withdrawalRepository = $withdrawalRepository;
        $this->transactionService = $transactionService;
        $this->logger = $logger;
    }

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $page = $request->query->getInt('page', 1);
        $limit = 50;

        $criteria = [];
        if ($status) {
            $criteria['status'] = $status;
        }

        $withdrawals = $this->withdrawalRepository->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit,
            ($page - 1) * $limit
        );

        $total = $this->withdrawalRepository->count($criteria);

        $stats = [
            'pending' => $this->withdrawalRepository->count(['status' => 'pending']),
            'processing' => $this->withdrawalRepository->count(['status' => 'processing']),
            'completed' => $this->withdrawalRepository->count(['status' => 'completed']),
            'failed' => $this->withdrawalRepository->count(['status' => 'failed']),
            'cancelled' => $this->withdrawalRepository->count(['status' => 'cancelled'])
        ];

        return $this->render('admin/withdrawal/index.html.twig', [
            'withdrawals' => $withdrawals,
            'stats' => $stats,
            'current_status' => $status,
            'pagination' => [
                'page' => $page,
                'pages' => ceil($total / $limit),
                'total' => $total
            ]
        ]);
    }

    #[Route('/{id}', name: 'detail', requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal) {
            throw $this->createNotFoundException('Withdrawal not found');
        }

        $user = $withdrawal->getUser();
        $userStats = [
            'total_deposits' => $this->withdrawalRepository->getUserTotalDeposits($user),
            'total_withdrawals' => $this->withdrawalRepository->getUserTotalWithdrawals($user),
            'pending_withdrawals' => $this->withdrawalRepository->getUserPendingWithdrawals($user),
            'last_withdrawal' => $this->withdrawalRepository->getUserLastWithdrawal($user)
        ];

        return $this->render('admin/withdrawal/detail.html.twig', [
            'withdrawal' => $withdrawal,
            'user_stats' => $userStats
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal) {
            return new JsonResponse(['error' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse(['error' => 'Withdrawal is not pending'], 400);
        }

        try {
            /** @var User $admin */
            $admin = $this->getUser();

            // Add approval
            $withdrawal->addApproval($admin);

            // Check if we have enough approvals
            $requiredApprovals = 2;
            $currentApprovals = count($withdrawal->getApprovals());

            if ($currentApprovals >= $requiredApprovals) {
                // Process withdrawal
                $this->transactionService->processWithdrawal($withdrawal, $admin);

                $message = 'Withdrawal approved and processed';
                $this->logger->info('Withdrawal processed', [
                    'withdrawal_id' => $withdrawal->getId(),
                    'admin_id' => $admin->getId(),
                    'amount' => $withdrawal->getAmount()
                ]);
            } else {
                // Save approval
                $this->withdrawalRepository->save($withdrawal, true);

                $message = sprintf(
                    'Approval added (%d/%d required)',
                    $currentApprovals,
                    $requiredApprovals
                );

                $this->logger->info('Withdrawal approval added', [
                    'withdrawal_id' => $withdrawal->getId(),
                    'admin_id' => $admin->getId(),
                    'approvals' => $currentApprovals
                ]);
            }

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $message,
                    'approvals' => $currentApprovals,
                    'status' => $withdrawal->getStatus()
                ]);
            }

            $this->addFlash('success', $message);
            return $this->redirectToRoute('admin_withdrawal_index');

        } catch (Exception $e) {
            $this->logger->error('Failed to approve withdrawal', [
                'withdrawal_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $e->getMessage()], 500);
            }

            $this->addFlash('error', 'Failed to approve withdrawal: ' . $e->getMessage());
            return $this->redirectToRoute('admin_withdrawal_detail', ['id' => $id]);
        }
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal) {
            return new JsonResponse(['error' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse(['error' => 'Withdrawal is not pending'], 400);
        }

        $reason = $request->request->get('reason', 'Rejected by administrator');

        try {
            $this->transactionService->cancelWithdrawal($withdrawal, $reason);

            /** @var User $admin */
            $admin = $this->getUser();

            $this->logger->info('Withdrawal rejected', [
                'withdrawal_id' => $withdrawal->getId(),
                'admin_id' => $admin->getId(),
                'reason' => $reason
            ]);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Withdrawal rejected'
                ]);
            }

            $this->addFlash('success', 'Withdrawal rejected');
            return $this->redirectToRoute('admin_withdrawal_index');

        } catch (Exception $e) {
            $this->logger->error('Failed to reject withdrawal', [
                'withdrawal_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $e->getMessage()], 500);
            }

            $this->addFlash('error', 'Failed to reject withdrawal: ' . $e->getMessage());
            return $this->redirectToRoute('admin_withdrawal_detail', ['id' => $id]);
        }
    }

    #[Route('/batch-approve', name: 'batch_approve', methods: ['POST'])]
    public function batchApprove(Request $request): JsonResponse
    {
        $ids = $request->request->all('ids');

        if (empty($ids)) {
            return new JsonResponse(['error' => 'No withdrawals selected'], 400);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $results = [
            'approved' => 0,
            'processed' => 0,
            'errors' => []
        ];

        foreach ($ids as $id) {
            try {
                $withdrawal = $this->withdrawalRepository->find($id);

                if (!$withdrawal || $withdrawal->getStatus() !== 'pending') {
                    continue;
                }

                $withdrawal->addApproval($admin);

                if (count($withdrawal->getApprovals()) >= 2) {
                    $this->transactionService->processWithdrawal($withdrawal, $admin);
                    $results['processed']++;
                } else {
                    $this->withdrawalRepository->save($withdrawal, true);
                    $results['approved']++;
                }

            } catch (Exception $e) {
                $results['errors'][] = [
                    'id' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }

        return new JsonResponse([
            'success' => true,
            'results' => $results
        ]);
    }

    #[Route('/pending-summary', name: 'pending_summary')]
    public function pendingSummary(): JsonResponse
    {
        $pending = $this->withdrawalRepository->findBy(['status' => 'pending']);

        $summary = [
            'count' => count($pending),
            'total_amount' => 0,
            'by_type' => [
                'bonus' => ['count' => 0, 'amount' => 0],
                'deposit' => ['count' => 0, 'amount' => 0]
            ],
            'requiring_approval' => [],
            'ready_to_process' => []
        ];

        foreach ($pending as $withdrawal) {
            $summary['total_amount'] += $withdrawal->getAmount();
            $summary['by_type'][$withdrawal->getType()]['count']++;
            $summary['by_type'][$withdrawal->getType()]['amount'] += $withdrawal->getAmount();

            $approvalCount = count($withdrawal->getApprovals());

            if ($approvalCount === 0) {
                $summary['requiring_approval'][] = [
                    'id' => $withdrawal->getId(),
                    'user' => $withdrawal->getUser()->getUsername(),
                    'amount' => $withdrawal->getAmount(),
                    'type' => $withdrawal->getType(),
                    'created_at' => $withdrawal->getCreatedAt()->format('c')
                ];
            } elseif ($approvalCount === 1) {
                $summary['ready_to_process'][] = [
                    'id' => $withdrawal->getId(),
                    'user' => $withdrawal->getUser()->getUsername(),
                    'amount' => $withdrawal->getAmount(),
                    'type' => $withdrawal->getType(),
                    'approvals' => $approvalCount,
                    'created_at' => $withdrawal->getCreatedAt()->format('c')
                ];
            }
        }

        return new JsonResponse($summary);
    }

    #[Route('/export', name: 'export')]
    public function export(Request $request): Response
    {
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        $criteria = [];
        if ($status) {
            $criteria['status'] = $status;
        }

        $withdrawals = $this->withdrawalRepository->findByCriteria($criteria, $dateFrom, $dateTo);

        $csv = "ID,User,Amount,Type,Address,Status,TX Hash,Created At,Processed At,Approvals\n";

        foreach ($withdrawals as $withdrawal) {
            $csv .= sprintf(
                "%d,%s,%.2f,%s,%s,%s,%s,%s,%s,%d\n",
                $withdrawal->getId(),
                $withdrawal->getUser()->getUsername(),
                $withdrawal->getAmount(),
                $withdrawal->getType(),
                $withdrawal->getAddress(),
                $withdrawal->getStatus(),
                $withdrawal->getTxHash() ?? '',
                $withdrawal->getCreatedAt()->format('Y-m-d H:i:s'),
                $withdrawal->getProcessedAt() ? $withdrawal->getProcessedAt()->format('Y-m-d H:i:s') : '',
                count($withdrawal->getApprovals())
            );
        }

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="withdrawals_' . date('Y-m-d') . '.csv"');

        return $response;
    }

    #[Route('/check-limits', name: 'check_limits')]
    public function checkLimits(): JsonResponse
    {
        $hotWalletBalance = $this->tronService->getBalance($_ENV['HOT_WALLET_ADDRESS']);
        $pendingTotal = $this->withdrawalRepository->getPendingTotal();

        $limits = [
            'hot_wallet_balance' => $hotWalletBalance,
            'pending_total' => $pendingTotal,
            'available_for_withdrawal' => $hotWalletBalance - $pendingTotal,
            'warning' => $hotWalletBalance < ($pendingTotal * 1.5),
            'critical' => $hotWalletBalance < $pendingTotal
        ];

        return new JsonResponse($limits);
    }
}
