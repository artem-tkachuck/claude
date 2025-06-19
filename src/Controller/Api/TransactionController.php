<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\DepositRepository;
use App\Repository\WithdrawalRepository;
use App\Service\Blockchain\TronService;
use App\Service\Transaction\TransactionService;
use DateTime;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/transaction', name: 'api_transaction_')]
#[IsGranted('ROLE_USER')]
class TransactionController extends AbstractController
{
    private TransactionService $transactionService;
    private TronService $tronService;
    private DepositRepository $depositRepository;
    private WithdrawalRepository $withdrawalRepository;
    private LoggerInterface $logger;

    public function __construct(
        TransactionService   $transactionService,
        TronService          $tronService,
        DepositRepository    $depositRepository,
        WithdrawalRepository $withdrawalRepository,
        LoggerInterface      $logger
    )
    {
        $this->transactionService = $transactionService;
        $this->tronService = $tronService;
        $this->depositRepository = $depositRepository;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->logger = $logger;
    }

    #[Route('/deposit/check', name: 'deposit_check', methods: ['GET'])]
    public function checkDeposits(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->getDepositAddress()) {
            return new JsonResponse([
                'error' => 'No deposit address found'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Get last checked transaction
            $lastDeposit = $this->depositRepository->findOneBy(
                ['user' => $user],
                ['createdAt' => 'DESC']
            );

            $lastTxId = $lastDeposit ? $lastDeposit->getTxHash() : null;

            // Check for new transactions
            $transactions = $this->tronService->checkIncomingTransactions(
                $user->getDepositAddress(),
                $lastTxId
            );

            $newDeposits = [];

            foreach ($transactions as $tx) {
                // Skip if transaction already processed
                $existing = $this->depositRepository->findOneBy(['txHash' => $tx['txid']]);
                if ($existing) {
                    continue;
                }

                // Create deposit
                $deposit = $this->transactionService->createDeposit($user, $tx);

                $newDeposits[] = [
                    'id' => $deposit->getId(),
                    'amount' => $deposit->getAmount(),
                    'txHash' => $deposit->getTxHash(),
                    'confirmations' => $deposit->getConfirmations(),
                    'status' => $deposit->getStatus(),
                    'createdAt' => $deposit->getCreatedAt()->format('c')
                ];
            }

            return new JsonResponse([
                'new_deposits' => $newDeposits,
                'checked_at' => (new DateTime())->format('c')
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to check deposits', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to check deposits'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/deposit/address', name: 'deposit_address', methods: ['GET'])]
    public function getDepositAddress(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $address = $user->getDepositAddress();

        if (!$address) {
            // Generate new address if user doesn't have one
            try {
                $addressData = $this->tronService->generateDepositAddress();
                $user->setDepositAddress($addressData['address']);
                $user->setDepositAddressPrivateKey($addressData['privateKey']);

                $this->getDoctrine()->getManager()->flush();

                $address = $addressData['address'];

                $this->logger->info('Generated new deposit address', [
                    'user_id' => $user->getId(),
                    'address' => $address
                ]);
            } catch (Exception $e) {
                $this->logger->error('Failed to generate deposit address', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage()
                ]);

                return new JsonResponse([
                    'error' => 'Failed to generate deposit address'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse([
            'address' => $address,
            'network' => 'TRC20',
            'currency' => 'USDT',
            'min_amount' => 100,
            'confirmations_required' => 19,
            'qr_code' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($address)
        ]);
    }

    #[Route('/withdrawal/create', name: 'withdrawal_create', methods: ['POST'])]
    public function createWithdrawal(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['amount']) || !isset($data['address']) || !isset($data['type'])) {
            return new JsonResponse([
                'error' => 'Missing required fields: amount, address, type'
            ], Response::HTTP_BAD_REQUEST);
        }

        $amount = floatval($data['amount']);
        $address = $data['address'];
        $type = $data['type']; // 'bonus' or 'deposit'

        // Validate type
        if (!in_array($type, ['bonus', 'deposit'])) {
            return new JsonResponse([
                'error' => 'Invalid withdrawal type. Must be "bonus" or "deposit"'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $withdrawal = $this->transactionService->createWithdrawal($user, $amount, $address, $type);

            return new JsonResponse([
                'withdrawal' => [
                    'id' => $withdrawal->getId(),
                    'amount' => $withdrawal->getAmount(),
                    'address' => $withdrawal->getAddress(),
                    'type' => $withdrawal->getType(),
                    'status' => $withdrawal->getStatus(),
                    'createdAt' => $withdrawal->getCreatedAt()->format('c'),
                    'message' => 'Withdrawal request created. Pending admin approval.'
                ]
            ], Response::HTTP_CREATED);

        } catch (InvalidArgumentException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (RuntimeException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            $this->logger->error('Failed to create withdrawal', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return new JsonResponse([
                'error' => 'Failed to create withdrawal request'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/withdrawal/{id}', name: 'withdrawal_get', methods: ['GET'])]
    public function getWithdrawal(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal || $withdrawal->getUser()->getId() !== $user->getId()) {
            return new JsonResponse([
                'error' => 'Withdrawal not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'withdrawal' => [
                'id' => $withdrawal->getId(),
                'amount' => $withdrawal->getAmount(),
                'address' => $withdrawal->getAddress(),
                'type' => $withdrawal->getType(),
                'status' => $withdrawal->getStatus(),
                'txHash' => $withdrawal->getTxHash(),
                'approvals' => count($withdrawal->getApprovals()),
                'failureReason' => $withdrawal->getFailureReason(),
                'createdAt' => $withdrawal->getCreatedAt()->format('c'),
                'processedAt' => $withdrawal->getProcessedAt() ? $withdrawal->getProcessedAt()->format('c') : null
            ]
        ]);
    }

    #[Route('/withdrawal/{id}/cancel', name: 'withdrawal_cancel', methods: ['POST'])]
    public function cancelWithdrawal(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $withdrawal = $this->withdrawalRepository->find($id);

        if (!$withdrawal || $withdrawal->getUser()->getId() !== $user->getId()) {
            return new JsonResponse([
                'error' => 'Withdrawal not found'
            ], Response::HTTP_NOT_FOUND);
        }

        if ($withdrawal->getStatus() !== 'pending') {
            return new JsonResponse([
                'error' => 'Only pending withdrawals can be cancelled'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->transactionService->cancelWithdrawal($withdrawal, 'Cancelled by user');

            return new JsonResponse([
                'message' => 'Withdrawal cancelled successfully',
                'withdrawal' => [
                    'id' => $withdrawal->getId(),
                    'status' => $withdrawal->getStatus()
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to cancel withdrawal', [
                'withdrawal_id' => $id,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to cancel withdrawal'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/deposits', name: 'deposits_list', methods: ['GET'])]
    public function getDeposits(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);
        $status = $request->query->get('status');

        $limit = min($limit, 100);
        $offset = ($page - 1) * $limit;

        $criteria = ['user' => $user];
        if ($status) {
            $criteria['status'] = $status;
        }

        $deposits = $this->depositRepository->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );

        $total = $this->depositRepository->count($criteria);

        $data = array_map(function ($deposit) {
            return [
                'id' => $deposit->getId(),
                'amount' => $deposit->getAmount(),
                'txHash' => $deposit->getTxHash(),
                'fromAddress' => $deposit->getFromAddress(),
                'confirmations' => $deposit->getConfirmations(),
                'status' => $deposit->getStatus(),
                'createdAt' => $deposit->getCreatedAt()->format('c'),
                'confirmedAt' => $deposit->getConfirmedAt() ? $deposit->getConfirmedAt()->format('c') : null
            ];
        }, $deposits);

        return new JsonResponse([
            'deposits' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    #[Route('/withdrawals', name: 'withdrawals_list', methods: ['GET'])]
    public function getWithdrawals(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);
        $status = $request->query->get('status');
        $type = $request->query->get('type');

        $limit = min($limit, 100);
        $offset = ($page - 1) * $limit;

        $criteria = ['user' => $user];
        if ($status) {
            $criteria['status'] = $status;
        }
        if ($type) {
            $criteria['type'] = $type;
        }

        $withdrawals = $this->withdrawalRepository->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );

        $total = $this->withdrawalRepository->count($criteria);

        $data = array_map(function ($withdrawal) {
            return [
                'id' => $withdrawal->getId(),
                'amount' => $withdrawal->getAmount(),
                'address' => $withdrawal->getAddress(),
                'type' => $withdrawal->getType(),
                'status' => $withdrawal->getStatus(),
                'txHash' => $withdrawal->getTxHash(),
                'approvals' => count($withdrawal->getApprovals()),
                'failureReason' => $withdrawal->getFailureReason(),
                'createdAt' => $withdrawal->getCreatedAt()->format('c'),
                'processedAt' => $withdrawal->getProcessedAt() ? $withdrawal->getProcessedAt()->format('c') : null
            ];
        }, $withdrawals);

        return new JsonResponse([
            'withdrawals' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    #[Route('/estimate-fee', name: 'estimate_fee', methods: ['POST'])]
    public function estimateFee(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['amount']) || !isset($data['address'])) {
            return new JsonResponse([
                'error' => 'Missing required fields: amount, address'
            ], Response::HTTP_BAD_REQUEST);
        }

        $amount = floatval($data['amount']);
        $address = $data['address'];

        // Validate address
        if (!$this->tronService->validateAddress($address)) {
            return new JsonResponse([
                'error' => 'Invalid TRON address'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $fee = $this->tronService->estimateFee(
                $user->getDepositAddress() ?? '',
                $address,
                $amount
            );

            return new JsonResponse([
                'estimated_fee' => $fee,
                'currency' => 'TRX',
                'network' => 'TRC20'
            ]);

        } catch (Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to estimate fee'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
