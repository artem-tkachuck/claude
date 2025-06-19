<?php

namespace App\Service\Report;

use App\Repository\BonusRepository;
use App\Repository\DepositRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Repository\WithdrawalRepository;
use DateTime;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

class ReportService
{
    private UserRepository $userRepository;
    private DepositRepository $depositRepository;
    private WithdrawalRepository $withdrawalRepository;
    private BonusRepository $bonusRepository;
    private TransactionRepository $transactionRepository;
    private Environment $twig;

    public function __construct(
        UserRepository        $userRepository,
        DepositRepository     $depositRepository,
        WithdrawalRepository  $withdrawalRepository,
        BonusRepository       $bonusRepository,
        TransactionRepository $transactionRepository,
        Environment           $twig
    )
    {
        $this->userRepository = $userRepository;
        $this->depositRepository = $depositRepository;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->bonusRepository = $bonusRepository;
        $this->transactionRepository = $transactionRepository;
        $this->twig = $twig;
    }

    /**
     * Generate financial report
     */
    public function generateFinancialReport(DateTime $from, DateTime $to): array
    {
        $data = [
            'period' => [
                'from' => $from,
                'to' => $to,
                'days' => $from->diff($to)->days + 1
            ],
            'deposits' => $this->getDepositStats($from, $to),
            'withdrawals' => $this->getWithdrawalStats($from, $to),
            'bonuses' => $this->getBonusStats($from, $to),
            'balance' => $this->getBalanceStats($from, $to),
            'profit_loss' => $this->calculateProfitLoss($from, $to),
            'charts' => $this->generateChartData($from, $to)
        ];

        return $data;
    }

    /**
     * Get deposit statistics
     */
    private function getDepositStats(DateTime $from, DateTime $to): array
    {
        return [
            'count' => $this->depositRepository->countByDateRange($from, $to),
            'total' => $this->depositRepository->getTotalByDateRange($from, $to),
            'average' => $this->depositRepository->getAverageByDateRange($from, $to),
            'median' => $this->depositRepository->getMedianByDateRange($from, $to),
            'by_status' => $this->depositRepository->getByStatus($from, $to),
            'top_depositors' => $this->depositRepository->getTopDepositors($from, $to, 10),
            'by_day' => $this->depositRepository->getDailyBreakdown($from, $to),
            'growth' => $this->calculateGrowth(
                $this->depositRepository->getTotalByDateRange(
                    (clone $from)->modify('-' . $from->diff($to)->days . ' days'),
                    $from
                ),
                $this->depositRepository->getTotalByDateRange($from, $to)
            )
        ];
    }

    /**
     * Calculate growth rate
     */
    private function calculateGrowth(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Get withdrawal statistics
     */
    private function getWithdrawalStats(DateTime $from, DateTime $to): array
    {
        return [
            'count' => $this->withdrawalRepository->countByDateRange($from, $to),
            'total' => $this->withdrawalRepository->getTotalByDateRange($from, $to),
            'average' => $this->withdrawalRepository->getAverageByDateRange($from, $to),
            'by_type' => $this->withdrawalRepository->getByType($from, $to),
            'by_status' => $this->withdrawalRepository->getByStatus($from, $to),
            'processing_time' => [
                'average' => $this->withdrawalRepository->getAverageProcessingTime($from, $to),
                'median' => $this->withdrawalRepository->getMedianProcessingTime($from, $to)
            ],
            'approval_stats' => $this->withdrawalRepository->getApprovalStats($from, $to)
        ];
    }

    /**
     * Get bonus statistics
     */
    private function getBonusStats(DateTime $from, DateTime $to): array
    {
        return [
            'total_distributed' => $this->bonusRepository->getTotalByDateRange($from, $to),
            'count' => $this->bonusRepository->countByDateRange($from, $to),
            'average' => $this->bonusRepository->getAverageByDateRange($from, $to),
            'by_type' => $this->bonusRepository->getByType($from, $to),
            'daily_profit' => $this->bonusRepository->getDailyProfitStats($from, $to),
            'referral' => $this->bonusRepository->getReferralBonusStats($from, $to),
            'top_recipients' => $this->bonusRepository->getTopRecipients($from, $to, 10)
        ];
    }

    /**
     * Get balance statistics
     */
    private function getBalanceStats(DateTime $from, DateTime $to): array
    {
        $currentDeposits = $this->userRepository->getTotalDepositsBalance();
        $currentBonuses = $this->userRepository->getTotalBonusesBalance();

        return [
            'current' => [
                'deposits' => $currentDeposits,
                'bonuses' => $currentBonuses,
                'total' => $currentDeposits + $currentBonuses
            ],
            'hot_wallet' => $this->getHotWalletBalance(),
            'cold_wallet' => $this->getColdWalletBalance(),
            'flow' => [
                'deposits_in' => $this->depositRepository->getTotalByDateRange($from, $to),
                'withdrawals_out' => $this->withdrawalRepository->getTotalByDateRange($from, $to),
                'net' => $this->depositRepository->getTotalByDateRange($from, $to) -
                    $this->withdrawalRepository->getTotalByDateRange($from, $to)
            ]
        ];
    }

    /**
     * Get hot wallet balance (placeholder)
     */
    private function getHotWalletBalance(): float
    {
// This would connect to blockchain service
        return 50000.0;
    }

    /**
     * Get cold wallet balance (placeholder)
     */
    private function getColdWalletBalance(): float
    {
// This would connect to blockchain service
        return 450000.0;
    }

    /**
     * Calculate profit/loss
     */
    private function calculateProfitLoss(DateTime $from, DateTime $to): array
    {
        $deposits = $this->depositRepository->getTotalByDateRange($from, $to);
        $withdrawals = $this->withdrawalRepository->getTotalByDateRange($from, $to);
        $bonusesDistributed = $this->bonusRepository->getTotalByDateRange($from, $to);

// Assuming company keeps 30% of trading profits
        $tradingRevenue = $bonusesDistributed * 0.3 / 0.7; // If 70% was distributed, calculate 100%
        $companyProfit = $tradingRevenue * 0.3;

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'bonuses_distributed' => $bonusesDistributed,
            'trading_revenue' => $tradingRevenue,
            'company_profit' => $companyProfit,
            'net_flow' => $deposits - $withdrawals,
            'roi' => $deposits > 0 ? ($companyProfit / $deposits) * 100 : 0
        ];
    }

    /**
     * Generate chart data
     */
    private function generateChartData(DateTime $from, DateTime $to): array
    {
        $days = $from->diff($to)->days + 1;
        $interval = $days <= 31 ? 'daily' : ($days <= 365 ? 'weekly' : 'monthly');

        return [
            'deposits_timeline' => $this->getTimelineData('deposits', $from, $to, $interval),
            'withdrawals_timeline' => $this->getTimelineData('withdrawals', $from, $to, $interval),
            'user_growth' => $this->getUserGrowthData($from, $to, $interval),
            'bonus_distribution' => $this->getBonusDistributionData($from, $to),
            'deposit_distribution' => $this->getDepositDistributionData()
        ];
    }

    /**
     * Get timeline data
     */
    private function getTimelineData(string $type, DateTime $from, DateTime $to, string $interval): array
    {
        $repository = match ($type) {
            'deposits' => $this->depositRepository,
            'withdrawals' => $this->withdrawalRepository,
            default => throw new InvalidArgumentException('Invalid type')
        };

        return $repository->getTimelineData($from, $to, $interval);
    }

    private function getUserGrowthData(DateTime $from, DateTime $to, string $interval): array
    {
        return $this->userRepository->getGrowthData($from, $to, $interval);
    }

    private function getBonusDistributionData(DateTime $from, DateTime $to): array
    {
        return $this->bonusRepository->getDistributionData($from, $to);
    }

    private function getDepositDistributionData(): array
    {
        return $this->depositRepository->getDistributionData();
    }

    /**
     * Generate user analytics report
     */
    public function generateUserAnalyticsReport(DateTime $from, DateTime $to): array
    {
        return [
            'period' => [
                'from' => $from,
                'to' => $to
            ],
            'acquisition' => $this->getUserAcquisitionStats($from, $to),
            'retention' => $this->getUserRetentionStats($from, $to),
            'activity' => $this->getUserActivityStats($from, $to),
            'segments' => $this->getUserSegments(),
            'referrals' => $this->getReferralStats($from, $to),
            'geography' => $this->getGeographyStats()
        ];
    }

    /**
     * Get user acquisition statistics
     */
    private function getUserAcquisitionStats(DateTime $from, DateTime $to): array
    {
        return [
            'new_users' => $this->userRepository->countNewUsers($from, $to),
            'by_source' => [
                'direct' => $this->userRepository->countBySource('direct', $from, $to),
                'referral' => $this->userRepository->countBySource('referral', $from, $to),
                'campaign' => $this->userRepository->countBySource('campaign', $from, $to)
            ],
            'conversion_rate' => $this->calculateConversionRate($from, $to),
            'activation_rate' => $this->calculateActivationRate($from, $to),
            'cost_per_acquisition' => $this->calculateCPA($from, $to)
        ];
    }

    /**
     * Calculate conversion rate
     */
    private function calculateConversionRate(DateTime $from, DateTime $to): float
    {
        $newUsers = $this->userRepository->countNewUsers($from, $to);
        $usersWithDeposit = $this->userRepository->countNewUsersWithDeposit($from, $to);

        return $newUsers > 0 ? ($usersWithDeposit / $newUsers) * 100 : 0;
    }

    /**
     * Calculate activation rate
     */
    private function calculateActivationRate(DateTime $from, DateTime $to): float
    {
        $newUsers = $this->userRepository->countNewUsers($from, $to);
        $activeUsers = $this->userRepository->countNewActiveUsers($from, $to);

        return $newUsers > 0 ? ($activeUsers / $newUsers) * 100 : 0;
    }

    /**
     * Calculate cost per acquisition
     */
    private function calculateCPA(DateTime $from, DateTime $to): float
    {
// This would calculate based on marketing spend
        return 25.0; // Placeholder
    }

    /**
     * Get user retention statistics
     */
    private function getUserRetentionStats(DateTime $from, DateTime $to): array
    {
        $cohorts = $this->generateCohorts($from, $to);

        return [
            'retention_curve' => $this->calculateRetentionCurve($cohorts),
            'churn_rate' => $this->calculateChurnRate($from, $to),
            'lifetime_value' => $this->calculateAverageLTV(),
            'cohort_analysis' => $cohorts
        ];
    }

    /**
     * Generate user cohorts
     */
    private function generateCohorts(DateTime $from, DateTime $to): array
    {
// Generate weekly cohorts
        $cohorts = [];
        $current = clone $from;

        while ($current <= $to) {
            $weekEnd = min(clone $current->modify('+6 days'), $to);
            $cohorts[] = [
                'week' => $current->format('Y-W'),
                'users' => $this->userRepository->getCohortData($current, $weekEnd)
            ];
            $current->modify('+1 day');
        }

        return $cohorts;
    }

    /**
     * Calculate retention curve
     */
    private function calculateRetentionCurve(array $cohorts): array
    {
        $curve = [];

        foreach ([1, 7, 14, 30, 60, 90] as $day) {
            $totalUsers = 0;
            $retainedUsers = 0;

            foreach ($cohorts as $cohort) {
                if (isset($cohort['users'][$day])) {
                    $totalUsers += $cohort['users']['total'];
                    $retainedUsers += $cohort['users'][$day];
                }
            }

            $curve['day_' . $day] = $totalUsers > 0 ? ($retainedUsers / $totalUsers) * 100 : 0;
        }

        return $curve;
    }

    /**
     * Calculate churn rate
     */
    private function calculateChurnRate(DateTime $from, DateTime $to): float
    {
        $activeAtStart = $this->userRepository->countActiveUsers(
            (clone $from)->modify('-30 days'),
            $from
        );

        $stillActive = $this->userRepository->countRetainedUsers($from, $to);

        return $activeAtStart > 0 ? (($activeAtStart - $stillActive) / $activeAtStart) * 100 : 0;
    }

    /**
     * Calculate average lifetime value
     */
    private function calculateAverageLTV(): float
    {
        return $this->userRepository->getAverageLTV();
    }

    /**
     * Get user activity statistics
     */
    private function getUserActivityStats(DateTime $from, DateTime $to): array
    {
        return [
            'daily_active_users' => $this->userRepository->getDAU($from, $to),
            'weekly_active_users' => $this->userRepository->getWAU($from, $to),
            'monthly_active_users' => $this->userRepository->getMAU($from, $to),
            'average_session_duration' => $this->calculateAverageSessionDuration($from, $to),
            'actions_per_user' => $this->calculateActionsPerUser($from, $to),
            'peak_hours' => $this->getPeakActivityHours($from, $to)
        ];
    }

    /**
     * Other helper methods...
     */
    private function calculateAverageSessionDuration(DateTime $from, DateTime $to): float
    {
// Placeholder
        return 12.5; // minutes
    }

    private function calculateActionsPerUser(DateTime $from, DateTime $to): float
    {
// Placeholder
        return 3.2;
    }

    private function getPeakActivityHours(DateTime $from, DateTime $to): array
    {
// Placeholder
        return [
            ['hour' => 14, 'activity' => 100],
            ['hour' => 15, 'activity' => 95],
            ['hour' => 21, 'activity' => 88]
        ];
    }

    /**
     * Get user segments
     */
    private function getUserSegments(): array
    {
        return [
            'by_value' => [
                'whales' => $this->userRepository->countWhales(),
                'dolphins' => $this->userRepository->countDolphins(),
                'minnows' => $this->userRepository->countMinnows()
            ],
            'by_activity' => [
                'very_active' => $this->userRepository->countVeryActiveUsers(),
                'active' => $this->userRepository->countActiveUsers(),
                'dormant' => $this->userRepository->countDormantUsers()
            ],
            'by_lifetime' => [
                'new' => $this->userRepository->countUsersByLifetime(0, 7),
                'regular' => $this->userRepository->countUsersByLifetime(7, 30),
                'loyal' => $this->userRepository->countUsersByLifetime(30, 90),
                'veteran' => $this->userRepository->countUsersByLifetime(90, null)
            ]
        ];
    }

    private function getReferralStats(DateTime $from, DateTime $to): array
    {
        return $this->userRepository->getReferralStats($from, $to);
    }

    private function getGeographyStats(): array
    {
        return $this->userRepository->getGeographyStats();
    }

    /**
     * Generate transaction report
     */
    public function generateTransactionReport(DateTime $from, DateTime $to, array $filters = []): array
    {
        $transactions = $this->transactionRepository->findByDateRangeWithFilters($from, $to, $filters);

        return [
            'period' => [
                'from' => $from,
                'to' => $to
            ],
            'summary' => [
                'total_count' => count($transactions),
                'total_volume' => array_sum(array_map(fn($t) => abs($t->getAmount()), $transactions)),
                'by_type' => $this->groupTransactionsByType($transactions),
                'by_status' => $this->groupTransactionsByStatus($transactions)
            ],
            'transactions' => array_map(fn($t) => $this->formatTransaction($t), $transactions)
        ];
    }

    private function groupTransactionsByType(array $transactions): array
    {
        $grouped = [];
        foreach ($transactions as $transaction) {
            $type = $transaction->getType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [
                    'count' => 0,
                    'volume' => 0
                ];
            }
            $grouped[$type]['count']++;
            $grouped[$type]['volume'] += abs($transaction->getAmount());
        }
        return $grouped;
    }

    private function groupTransactionsByStatus(array $transactions): array
    {
        $grouped = [];
        foreach ($transactions as $transaction) {
            $status = $transaction->getStatus();
            if (!isset($grouped[$status])) {
                $grouped[$status] = 0;
            }
            $grouped[$status]++;
        }
        return $grouped;
    }

    /**
     * Format transaction for report
     */
    private function formatTransaction($transaction): array
    {
        return [
            'id' => $transaction->getId(),
            'date' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
            'user' => $transaction->getUser()->getUsername(),
            'type' => $transaction->getType(),
            'amount' => $transaction->getAmount(),
            'status' => $transaction->getStatus(),
            'tx_hash' => $transaction->getTxHash()
        ];
    }

    /**
     * Export report to Excel
     */
    public function exportToExcel(array $reportData, string $reportType): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        switch ($reportType) {
            case 'financial':
                $this->buildFinancialExcel($spreadsheet, $reportData);
                break;
            case 'users':
                $this->buildUserAnalyticsExcel($spreadsheet, $reportData);
                break;
            case 'transactions':
                $this->buildTransactionExcel($spreadsheet, $reportData);
                break;
        }

        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $filename = sprintf('%s_report_%s.xlsx', $reportType, date('Y-m-d'));

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    /**
     * Build financial Excel report
     */
    private function buildFinancialExcel(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Financial Summary');

// Header
        $sheet->setCellValue('A1', 'Financial Report');
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

// Period
        $sheet->setCellValue('A3', 'Period:');
        $sheet->setCellValue('B3', $data['period']['from']->format('Y-m-d') . ' to ' . $data['period']['to']->format('Y-m-d'));

// Deposits section
        $row = 5;
        $sheet->setCellValue('A' . $row, 'DEPOSITS');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Total Count');
        $sheet->setCellValue('B' . $row, $data['deposits']['count']);
        $row++;

        $sheet->setCellValue('A' . $row, 'Total Amount');
        $sheet->setCellValue('B' . $row, $data['deposits']['total']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;

        $sheet->setCellValue('A' . $row, 'Average Amount');
        $sheet->setCellValue('B' . $row, $data['deposits']['average']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row += 2;

// Withdrawals section
        $sheet->setCellValue('A' . $row, 'WITHDRAWALS');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Total Count');
        $sheet->setCellValue('B' . $row, $data['withdrawals']['count']);
        $row++;

        $sheet->setCellValue('A' . $row, 'Total Amount');
        $sheet->setCellValue('B' . $row, $data['withdrawals']['total']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row += 2;

// Profit/Loss section
        $sheet->setCellValue('A' . $row, 'PROFIT/LOSS');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Net Flow');
        $sheet->setCellValue('B' . $row, $data['profit_loss']['net_flow']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;

        $sheet->setCellValue('A' . $row, 'Company Profit');
        $sheet->setCellValue('B' . $row, $data['profit_loss']['company_profit']);
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

// Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

// Add daily breakdown sheet
        $this->addDailyBreakdownSheet($spreadsheet, $data);
    }

    /**
     * Add daily breakdown sheet
     */
    private function addDailyBreakdownSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Daily Breakdown');

// Headers
        $headers = ['Date', 'Deposits Count', 'Deposits Amount', 'Withdrawals Count', 'Withdrawals Amount', 'Net Flow'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }

// Data
        $row = 2;
        foreach ($data['deposits']['by_day'] as $date => $depositData) {
            $withdrawalData = $data['withdrawals']['by_day'][$date] ?? ['count' => 0, 'total' => 0];

            $sheet->setCellValue('A' . $row, $date);
            $sheet->setCellValue('B' . $row, $depositData['count']);
            $sheet->setCellValue('C' . $row, $depositData['total']);
            $sheet->setCellValue('D' . $row, $withdrawalData['count']);
            $sheet->setCellValue('E' . $row, $withdrawalData['total']);
            $sheet->setCellValue('F' . $row, $depositData['total'] - $withdrawalData['total']);

// Format numbers
            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');

            $row++;
        }

// Auto-size columns
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Generate PDF report
     */
    public function generatePdfReport(array $reportData, string $reportType): string
    {
        $html = $this->twig->render('reports/pdf/' . $reportType . '.html.twig', [
            'data' => $reportData,
            'generated_at' => new DateTime()
        ]);

// You would use a PDF library like TCPDF or wkhtmltopdf here
// This is a placeholder
        return $html;
    }
}