<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\BonusRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\Security\TwoFactorService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/user', name: 'api_user_')]
#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private TransactionRepository $transactionRepository;
    private BonusRepository $bonusRepository;
    private TwoFactorService $twoFactorService;
    private ValidatorInterface $validator;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserRepository         $userRepository,
        TransactionRepository  $transactionRepository,
        BonusRepository        $bonusRepository,
        TwoFactorService       $twoFactorService,
        ValidatorInterface     $validator
    )
    {
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->transactionRepository = $transactionRepository;
        $this->bonusRepository = $bonusRepository;
        $this->twoFactorService = $twoFactorService;
        $this->validator = $validator;
    }

    #[Route('/balance', name: 'balance', methods: ['GET'])]
    public function getBalance(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse([
            'balances' => [
                'deposit' => $user->getDepositBalance(),
                'bonus' => $user->getBonusBalance(),
                'total' => $user->getTotalBalance()
            ],
            'deposit_address' => $user->getDepositAddress(),
            'currency' => 'USDT'
        ]);
    }

    #[Route('/transactions', name: 'transactions', methods: ['GET'])]
    public function getTransactions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);
        $type = $request->query->get('type');

        $limit = min($limit, 100); // Max 100 per page
        $offset = ($page - 1) * $limit;

        $criteria = ['user' => $user];
        if ($type) {
            $criteria['type'] = $type;
        }

        $transactions = $this->transactionRepository->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );

        $total = $this->transactionRepository->count($criteria);

        $data = array_map(function ($tx) {
            return [
                'id' => $tx->getId(),
                'type' => $tx->getType(),
                'amount' => $tx->getAmount(),
                'status' => $tx->getStatus(),
                'txHash' => $tx->getTxHash(),
                'metadata' => $tx->getMetadata(),
                'createdAt' => $tx->getCreatedAt()->format('c')
            ];
        }, $transactions);

        return new JsonResponse([
            'transactions' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    #[Route('/bonuses', name: 'bonuses', methods: ['GET'])]
    public function getBonuses(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);

        $limit = min($limit, 100);
        $offset = ($page - 1) * $limit;

        $bonuses = $this->bonusRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );

        $total = $this->bonusRepository->count(['user' => $user]);

        $data = array_map(function ($bonus) {
            return [
                'id' => $bonus->getId(),
                'type' => $bonus->getType(),
                'amount' => $bonus->getAmount(),
                'description' => $bonus->getDescription(),
                'status' => $bonus->getStatus(),
                'createdAt' => $bonus->getCreatedAt()->format('c')
            ];
        }, $bonuses);

        return new JsonResponse([
            'bonuses' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    #[Route('/referrals', name: 'referrals', methods: ['GET'])]
    public function getReferrals(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $referralStats = $this->userRepository->getReferralStats($user);
        $referrals = $this->userRepository->findBy(['referrer' => $user]);

        $referralData = array_map(function ($referral) {
            return [
                'id' => $referral->getId(),
                'username' => $referral->getUsername(),
                'level' => 1,
                'depositAmount' => $referral->getDepositBalance(),
                'joinedAt' => $referral->getCreatedAt()->format('c'),
                'isActive' => $referral->isActive()
            ];
        }, $referrals);

        // Get level 2 referrals
        $level2Referrals = [];
        foreach ($referrals as $referral) {
            $subReferrals = $this->userRepository->findBy(['referrer' => $referral]);
            foreach ($subReferrals as $subReferral) {
                $level2Referrals[] = [
                    'id' => $subReferral->getId(),
                    'username' => $subReferral->getUsername(),
                    'level' => 2,
                    'depositAmount' => $subReferral->getDepositBalance(),
                    'joinedAt' => $subReferral->getCreatedAt()->format('c'),
                    'isActive' => $subReferral->isActive(),
                    'referredBy' => $referral->getUsername()
                ];
            }
        }

        return new JsonResponse([
            'referral_link' => 'https://t.me/' . $_ENV['TELEGRAM_BOT_USERNAME'] . '?start=' . $user->getReferralCode(),
            'referral_code' => $user->getReferralCode(),
            'statistics' => $referralStats,
            'referrals' => array_merge($referralData, $level2Referrals)
        ]);
    }

    #[Route('/settings', name: 'settings', methods: ['GET', 'PUT'])]
    public function settings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('GET')) {
            return new JsonResponse([
                'settings' => [
                    'notifications' => [
                        'telegram' => $user->isNotificationsEnabled(),
                        'email' => $user->isEmailNotificationsEnabled()
                    ],
                    'security' => [
                        'twoFactorEnabled' => $user->isTwoFactorEnabled(),
                        'twoFactorMethod' => 'totp'
                    ],
                    'preferences' => [
                        'locale' => $user->getPreferredLocale(),
                        'timezone' => $user->getTimezone()
                    ],
                    'withdrawal' => [
                        'autoWithdrawal' => $user->isAutoWithdrawalEnabled(),
                        'autoWithdrawalMinAmount' => $user->getAutoWithdrawMinAmount(),
                        'defaultAddress' => $user->getDefaultWithdrawalAddress()
                    ]
                ]
            ]);
        }

        // Handle PUT request
        $data = json_decode($request->getContent(), true);

        if (isset($data['notifications'])) {
            if (isset($data['notifications']['telegram'])) {
                $user->setNotificationsEnabled($data['notifications']['telegram']);
            }
            if (isset($data['notifications']['email'])) {
                $user->setEmailNotificationsEnabled($data['notifications']['email']);
            }
        }

        if (isset($data['preferences'])) {
            if (isset($data['preferences']['locale'])) {
                $user->setPreferredLocale($data['preferences']['locale']);
            }
            if (isset($data['preferences']['timezone'])) {
                $user->setTimezone($data['preferences']['timezone']);
            }
        }

        if (isset($data['withdrawal'])) {
            if (isset($data['withdrawal']['autoWithdrawal'])) {
                $user->setAutoWithdrawalEnabled($data['withdrawal']['autoWithdrawal']);
            }
            if (isset($data['withdrawal']['autoWithdrawalMinAmount'])) {
                $user->setAutoWithdrawMinAmount($data['withdrawal']['autoWithdrawalMinAmount']);
            }
            if (isset($data['withdrawal']['defaultAddress'])) {
                $user->setDefaultWithdrawalAddress($data['withdrawal']['defaultAddress']);
            }
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Settings updated successfully'
        ]);
    }

    #[Route('/2fa/enable', name: '2fa_enable', methods: ['POST'])]
    public function enable2FA(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isTwoFactorEnabled()) {
            return new JsonResponse([
                'error' => '2FA is already enabled'
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? null;

        if (!$code) {
            // Generate secret and return QR code
            $secret = $this->twoFactorService->generateSecret($user);
            $qrCode = $this->twoFactorService->getQrCode($user);

            return new JsonResponse([
                'qr_code' => $qrCode,
                'secret' => $secret,
                'backup_codes' => []
            ]);
        }

        try {
            // Verify code and enable 2FA
            $this->twoFactorService->enable2FA($user, $code);

            return new JsonResponse([
                'message' => '2FA enabled successfully',
                'backup_codes' => $user->getTwoFactorBackupCodes()
            ]);
        } catch (Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/2fa/disable', name: '2fa_disable', methods: ['POST'])]
    public function disable2FA(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isTwoFactorEnabled()) {
            return new JsonResponse([
                'error' => '2FA is not enabled'
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? null;

        if (!$password) {
            return new JsonResponse([
                'error' => 'Password required'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->twoFactorService->disable2FA($user, $password);

            return new JsonResponse([
                'message' => '2FA disabled successfully'
            ]);
        } catch (Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function getStatistics(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $stats = [
            'total_deposits' => $this->transactionRepository->getUserTotalByType($user, 'deposit'),
            'total_withdrawals' => abs($this->transactionRepository->getUserTotalByType($user, 'withdrawal')),
            'total_bonuses' => $this->bonusRepository->getUserTotalBonuses($user),
            'referral_earnings' => $this->bonusRepository->getUserReferralEarnings($user),
            'daily_profit_earnings' => $this->bonusRepository->getUserDailyProfitEarnings($user),
            'account_age_days' => $user->getCreatedAt()->diff(new DateTime())->days,
            'last_deposit' => $this->transactionRepository->getLastTransactionDate($user, 'deposit'),
            'last_withdrawal' => $this->transactionRepository->getLastTransactionDate($user, 'withdrawal')
        ];

        return new JsonResponse($stats);
    }

    #[Route('/export', name: 'export', methods: ['POST'])]
    public function exportData(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        $format = $data['format'] ?? 'json';
        $type = $data['type'] ?? 'all'; // all, transactions, bonuses

        // Generate export data
        $exportData = [
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'created_at' => $user->getCreatedAt()->format('c')
            ]
        ];

        if ($type === 'all' || $type === 'transactions') {
            $transactions = $this->transactionRepository->findBy(['user' => $user]);
            $exportData['transactions'] = array_map(function ($tx) {
                return [
                    'id' => $tx->getId(),
                    'type' => $tx->getType(),
                    'amount' => $tx->getAmount(),
                    'status' => $tx->getStatus(),
                    'created_at' => $tx->getCreatedAt()->format('c')
                ];
            }, $transactions);
        }

        if ($type === 'all' || $type === 'bonuses') {
            $bonuses = $this->bonusRepository->findBy(['user' => $user]);
            $exportData['bonuses'] = array_map(function ($bonus) {
                return [
                    'id' => $bonus->getId(),
                    'type' => $bonus->getType(),
                    'amount' => $bonus->getAmount(),
                    'created_at' => $bonus->getCreatedAt()->format('c')
                ];
            }, $bonuses);
        }

        // In real implementation, you might want to generate a file and return download link
        return new JsonResponse([
            'data' => $exportData,
            'format' => $format,
            'generated_at' => (new DateTime())->format('c')
        ]);
    }
}