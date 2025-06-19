<?php

namespace App\Command;

use App\Repository\BonusRepository;
use App\Repository\DepositRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Repository\WithdrawalRepository;
use DateTime;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Twig\Environment;

#[AsCommand(
    name: 'app:generate-report',
    description: 'Generate system reports (daily, weekly, monthly)',
)]
class GenerateReportCommand extends Command
{
    private UserRepository $userRepository;
    private DepositRepository $depositRepository;
    private WithdrawalRepository $withdrawalRepository;
    private BonusRepository $bonusRepository;
    private TransactionRepository $transactionRepository;
    private MailerInterface $mailer;
    private Environment $twig;
    private string $projectDir;

    public function __construct(
        UserRepository        $userRepository,
        DepositRepository     $depositRepository,
        WithdrawalRepository  $withdrawalRepository,
        BonusRepository       $bonusRepository,
        TransactionRepository $transactionRepository,
        MailerInterface       $mailer,
        Environment           $twig,
        string                $projectDir
    )
    {
        parent::__construct();
        $this->userRepository = $userRepository;
        $this->depositRepository = $depositRepository;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->bonusRepository = $bonusRepository;
        $this->transactionRepository = $transactionRepository;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'Report type: daily, weekly, monthly, custom')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date (Y-m-d)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date (Y-m-d)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: html, csv, json', 'html')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path')
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Email report to addresses (comma-separated)')
            ->addOption('sections', 's', InputOption::VALUE_REQUIRED, 'Report sections to include (comma-separated)', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = $input->getArgument('type');
        $format = $input->getOption('format');
        $outputPath = $input->getOption('output');
        $emailAddresses = $input->getOption('email');
        $sections = $input->getOption('sections');

        $io->title('Generating Report');

        // Determine date range
        $dateRange = $this->getDateRange($type, $input);

        if (!$dateRange) {
            $io->error('Invalid date range');
            return Command::FAILURE;
        }

        $io->info(sprintf(
            'Generating %s report from %s to %s',
            $type,
            $dateRange['from']->format('Y-m-d'),
            $dateRange['to']->format('Y-m-d')
        ));

        try {
            // Collect report data
            $io->section('Collecting data...');
            $reportData = $this->collectReportData($dateRange, $sections);

            // Generate report in requested format
            $io->section('Generating report...');
            $reportContent = $this->generateReport($reportData, $format, $dateRange);

            // Save to file if requested
            if ($outputPath) {
                $this->saveReport($reportContent, $outputPath, $format);
                $io->success(sprintf('Report saved to: %s', $outputPath));
            } else {
                // Output to console if no file specified
                if ($format === 'json') {
                    $io->writeln(json_encode($reportData, JSON_PRETTY_PRINT));
                } else {
                    $io->writeln($reportContent);
                }
            }

            // Email report if requested
            if ($emailAddresses) {
                $io->section('Sending report via email...');
                $this->emailReport($reportContent, $format, $emailAddresses, $dateRange);
                $io->success('Report sent via email');
            }

            // Display summary
            $this->displaySummary($io, $reportData);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('Failed to generate report: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get date range based on report type
     */
    private function getDateRange(string $type, InputInterface $input): ?array
    {
        $from = null;
        $to = new DateTime('today 23:59:59');

        switch ($type) {
            case 'daily':
                $from = new DateTime('today 00:00:00');
                break;

            case 'weekly':
                $from = new DateTime('monday this week 00:00:00');
                break;

            case 'monthly':
                $from = new DateTime('first day of this month 00:00:00');
                break;

            case 'custom':
                $fromStr = $input->getOption('from');
                $toStr = $input->getOption('to');

                if (!$fromStr || !$toStr) {
                    return null;
                }

                $from = new DateTime($fromStr . ' 00:00:00');
                $to = new DateTime($toStr . ' 23:59:59');
                break;

            default:
                return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Collect report data
     */
    private function collectReportData(array $dateRange, string $sections): array
    {
        $data = [
            'period' => [
                'from' => $dateRange['from']->format('Y-m-d H:i:s'),
                'to' => $dateRange['to']->format('Y-m-d H:i:s'),
            ],
            'generated_at' => (new DateTime())->format('Y-m-d H:i:s'),
        ];

        $includeSections = $sections === 'all' ?
            ['users', 'deposits', 'withdrawals', 'bonuses', 'transactions', 'summary'] :
            explode(',', $sections);

        // Users section
        if (in_array('users', $includeSections)) {
            $data['users'] = [
                'total' => $this->userRepository->count([]),
                'new' => $this->userRepository->countNewUsers($dateRange['from']),
                'active' => $this->userRepository->countActiveUsers($dateRange['from'], $dateRange['to']),
                'with_deposits' => $this->userRepository->countUsersWithDeposits(),
                'by_country' => $this->userRepository->getUsersByCountry(),
                'top_depositors' => $this->userRepository->getTopDepositors(10),
                'top_referrers' => $this->userRepository->getTopReferrers(10),
            ];
        }

        // Deposits section
        if (in_array('deposits', $includeSections)) {
            $data['deposits'] = [
                'count' => $this->depositRepository->countByDateRange($dateRange['from'], $dateRange['to']),
                'total_amount' => $this->depositRepository->getTotalByDateRange($dateRange['from'], $dateRange['to']),
                'average_amount' => $this->depositRepository->getAverageByDateRange($dateRange['from'], $dateRange['to']),
                'by_status' => $this->depositRepository->getByStatus($dateRange['from'], $dateRange['to']),
                'daily_breakdown' => $this->depositRepository->getDailyBreakdown($dateRange['from'], $dateRange['to']),
                'largest' => $this->depositRepository->getLargestDeposits($dateRange['from'], $dateRange['to'], 10),
            ];
        }

        // Withdrawals section
        if (in_array('withdrawals', $includeSections)) {
            $data['withdrawals'] = [
                'count' => $this->withdrawalRepository->countByDateRange($dateRange['from'], $dateRange['to']),
                'total_amount' => $this->withdrawalRepository->getTotalByDateRange($dateRange['from'], $dateRange['to']),
                'by_type' => $this->withdrawalRepository->getByType($dateRange['from'], $dateRange['to']),
                'by_status' => $this->withdrawalRepository->getByStatus($dateRange['from'], $dateRange['to']),
                'average_processing_time' => $this->withdrawalRepository->getAverageProcessingTime($dateRange['from'], $dateRange['to']),
                'pending' => $this->withdrawalRepository->getPendingSummary(),
            ];
        }

        // Bonuses section
        if (in_array('bonuses', $includeSections)) {
            $data['bonuses'] = [
                'total_distributed' => $this->bonusRepository->getTotalByDateRange($dateRange['from'], $dateRange['to']),
                'by_type' => $this->bonusRepository->getByType($dateRange['from'], $dateRange['to']),
                'daily_breakdown' => $this->bonusRepository->getDailyBreakdown($dateRange['from'], $dateRange['to']),
                'top_recipients' => $this->bonusRepository->getTopRecipients($dateRange['from'], $dateRange['to'], 10),
                'referral_bonuses' => $this->bonusRepository->getReferralBonusStats($dateRange['from'], $dateRange['to']),
            ];
        }

        // Summary section
        if (in_array('summary', $includeSections)) {
            $data['summary'] = [
                'total_deposits_balance' => $this->userRepository->getTotalDepositsBalance(),
                'total_bonus_balance' => $this->userRepository->getTotalBonusesBalance(),
                'net_flow' => $this->calculateNetFlow($dateRange['from'], $dateRange['to']),
                'growth_rate' => $this->calculateGrowthRate($dateRange['from'], $dateRange['to']),
                'key_metrics' => $this->getKeyMetrics($dateRange['from'], $dateRange['to']),
            ];
        }

        return $data;
    }

    /**
     * Calculate net flow
     */
    private function calculateNetFlow(DateTime $from, DateTime $to): float
    {
        $deposits = $this->depositRepository->getTotalByDateRange($from, $to);
        $withdrawals = $this->withdrawalRepository->getTotalByDateRange($from, $to);

        return $deposits - $withdrawals;
    }

    /**
     * Calculate growth rate
     */
    private function calculateGrowthRate(DateTime $from, DateTime $to): float
    {
        $days = $from->diff($to)->days ?: 1;
        $previousFrom = clone $from;
        $previousFrom->modify("-{$days} days");
        $previousTo = clone $from;
        $previousTo->modify('-1 day');

        $currentDeposits = $this->depositRepository->getTotalByDateRange($from, $to);
        $previousDeposits = $this->depositRepository->getTotalByDateRange($previousFrom, $previousTo);

        if ($previousDeposits == 0) {
            return $currentDeposits > 0 ? 100 : 0;
        }

        return (($currentDeposits - $previousDeposits) / $previousDeposits) * 100;
    }

    /**
     * Get key metrics
     */
    private function getKeyMetrics(DateTime $from, DateTime $to): array
    {
        return [
            'user_retention_rate' => $this->calculateRetentionRate($from, $to),
            'average_deposit_per_user' => $this->calculateAverageDepositPerUser(),
            'conversion_rate' => $this->calculateConversionRate(),
            'churn_rate' => $this->calculateChurnRate($from, $to),
        ];
    }

    private function calculateRetentionRate(DateTime $from, DateTime $to): float
    {
        $activeLastMonth = $this->userRepository->countActiveUsers(
            (clone $from)->modify('-30 days'),
            $from
        );

        if ($activeLastMonth == 0) {
            return 0;
        }

        $stillActive = $this->userRepository->countRetainedUsers($from, $to);

        return ($stillActive / $activeLastMonth) * 100;
    }

    private function calculateAverageDepositPerUser(): float
    {
        $totalUsers = $this->userRepository->countUsersWithDeposits();

        if ($totalUsers == 0) {
            return 0;
        }

        $totalDeposits = $this->depositRepository->getTotalAmount();

        return $totalDeposits / $totalUsers;
    }

    private function calculateConversionRate(): float
    {
        $totalUsers = $this->userRepository->count([]);
        $usersWithDeposits = $this->userRepository->countUsersWithDeposits();

        if ($totalUsers == 0) {
            return 0;
        }

        return ($usersWithDeposits / $totalUsers) * 100;
    }

    private function calculateChurnRate(DateTime $from, DateTime $to): float
    {
        $activeAtStart = $this->userRepository->countActiveUsers(
            (clone $from)->modify('-60 days'),
            (clone $from)->modify('-30 days')
        );

        if ($activeAtStart == 0) {
            return 0;
        }

        $inactive = $this->userRepository->countInactiveUsers($from, $to);

        return ($inactive / $activeAtStart) * 100;
    }

    /**
     * Generate report content
     */
    private function generateReport(array $data, string $format, array $dateRange): string
    {
        switch ($format) {
            case 'html':
                return $this->twig->render('reports/system_report.html.twig', [
                    'data' => $data,
                    'date_range' => $dateRange
                ]);

            case 'csv':
                return $this->generateCsvReport($data);

            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);

            default:
                throw new InvalidArgumentException('Unsupported format: ' . $format);
        }
    }

    /**
     * Generate CSV report
     */
    private function generateCsvReport(array $data): string
    {
        $csv = "System Report\n";
        $csv .= "Generated: " . $data['generated_at'] . "\n";
        $csv .= "Period: " . $data['period']['from'] . " to " . $data['period']['to'] . "\n\n";

        // Users section
        if (isset($data['users'])) {
            $csv .= "USERS\n";
            $csv .= "Total Users," . $data['users']['total'] . "\n";
            $csv .= "New Users," . $data['users']['new'] . "\n";
            $csv .= "Active Users," . $data['users']['active'] . "\n";
            $csv .= "Users with Deposits," . $data['users']['with_deposits'] . "\n\n";
        }

        // Deposits section
        if (isset($data['deposits'])) {
            $csv .= "DEPOSITS\n";
            $csv .= "Total Count," . $data['deposits']['count'] . "\n";
            $csv .= "Total Amount," . $data['deposits']['total_amount'] . "\n";
            $csv .= "Average Amount," . $data['deposits']['average_amount'] . "\n\n";
        }

        // Add more sections as needed...

        return $csv;
    }

    /**
     * Save report to file
     */
    private function saveReport(string $content, string $path, string $format): void
    {
        $extension = $format === 'html' ? 'html' : ($format === 'csv' ? 'csv' : 'json');

        if (!str_ends_with($path, '.' . $extension)) {
            $path .= '.' . $extension;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Email report
     */
    private function emailReport(string $content, string $format, string $addresses, array $dateRange): void
    {
        $subject = sprintf(
            'System Report: %s to %s',
            $dateRange['from']->format('Y-m-d'),
            $dateRange['to']->format('Y-m-d')
        );

        $email = (new Email())
            ->from($_ENV['MAILER_FROM'])
            ->subject($subject);

        foreach (explode(',', $addresses) as $address) {
            $email->addTo(trim($address));
        }

        if ($format === 'html') {
            $email->html($content);
        } else {
            $email->text('Please find the attached report.');

            $filename = sprintf(
                'report_%s_%s.%s',
                $dateRange['from']->format('Y-m-d'),
                $dateRange['to']->format('Y-m-d'),
                $format
            );

            $email->addPart(new DataPart($content, $filename));
        }

        $this->mailer->send($email);
    }

    /**
     * Display summary
     */
    private function displaySummary(SymfonyStyle $io, array $data): void
    {
        $io->section('Report Summary');

        $summaryTable = [];

        if (isset($data['users'])) {
            $summaryTable[] = ['Total Users', $data['users']['total']];
            $summaryTable[] = ['New Users', $data['users']['new']];
        }

        if (isset($data['deposits'])) {
            $summaryTable[] = ['Deposits', sprintf('%d (%.2f USDT)', $data['deposits']['count'], $data['deposits']['total_amount'])];
        }

        if (isset($data['withdrawals'])) {
            $summaryTable[] = ['Withdrawals', sprintf('%d (%.2f USDT)', $data['withdrawals']['count'], $data['withdrawals']['total_amount'])];
        }

        if (isset($data['bonuses'])) {
            $summaryTable[] = ['Bonuses Distributed', sprintf('%.2f USDT', $data['bonuses']['total_distributed'])];
        }

        $io->table(['Metric', 'Value'], $summaryTable);
    }
}
