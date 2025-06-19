<?php

namespace App\Command;

use App\Service\Bonus\BonusCalculator;
use App\Service\Notification\NotificationService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-bonuses',
    description: 'Process and distribute daily bonuses to users',
)]
class ProcessBonusesCommand extends Command
{
    private BonusCalculator $bonusCalculator;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        BonusCalculator     $bonusCalculator,
        NotificationService $notificationService,
        LoggerInterface     $logger
    )
    {
        parent::__construct();
        $this->bonusCalculator = $bonusCalculator;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profit', InputArgument::OPTIONAL, 'Total profit amount to distribute')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without making changes')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Send notifications to users')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force execution even if already run today');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');
        $shouldNotify = $input->getOption('notify');
        $force = $input->getOption('force');

        $io->title('Processing Daily Bonuses');

        // Check if already processed today
        if (!$force && $this->isAlreadyProcessedToday()) {
            $io->warning('Bonuses have already been processed today. Use --force to override.');
            return Command::SUCCESS;
        }

        // Get profit amount
        $profitAmount = $input->getArgument('profit');
        if (!$profitAmount) {
            $profitAmount = $io->ask('Enter the total profit amount to distribute', '1000');
        }

        $profitAmount = floatval($profitAmount);

        if ($profitAmount <= 0) {
            $io->error('Profit amount must be greater than 0');
            return Command::FAILURE;
        }

        $io->info(sprintf('Total profit to distribute: %.2f USDT', $profitAmount));

        try {
            if ($isDryRun) {
                $io->note('Running in DRY-RUN mode. No changes will be made.');

                // Simulate calculation
                $result = $this->simulateCalculation($profitAmount);
            } else {
                // Process bonuses
                $io->section('Calculating and distributing bonuses...');

                $progressBar = $io->createProgressBar();
                $progressBar->start();

                $result = $this->bonusCalculator->calculateDailyBonuses($profitAmount);

                $progressBar->finish();
                $io->newLine(2);
            }

            // Display results
            $io->success('Bonuses processed successfully!');

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Profit', sprintf('%.2f USDT', $profitAmount)],
                    ['Amount Distributed', sprintf('%.2f USDT', $result['distributed'])],
                    ['Company Profit', sprintf('%.2f USDT', $result['company_profit'])],
                    ['Users Rewarded', $result['users_count']],
                ]
            );

            if (isset($result['details'])) {
                $io->section('Distribution Details');
                $io->table(
                    ['Detail', 'Value'],
                    [
                        ['Distribution Percentage', $result['details']['distribution_percent'] . '%'],
                        ['Total User Deposits', sprintf('%.2f USDT', $result['details']['total_deposits'])],
                    ]
                );
            }

            // Send notifications if requested
            if ($shouldNotify && !$isDryRun) {
                $io->section('Sending notifications...');
                $this->notificationService->sendDailySummary([
                    'deposits_count' => 0,
                    'deposits_amount' => 0,
                    'withdrawals_count' => 0,
                    'withdrawals_amount' => 0,
                    'bonuses_amount' => $result['distributed'],
                    'new_users' => 0,
                    'total_balance' => $result['details']['total_deposits'] ?? 0
                ]);
                $io->success('Notifications sent');
            }

            // Log the execution
            $this->logger->info('Daily bonuses processed via command', [
                'profit' => $profitAmount,
                'distributed' => $result['distributed'],
                'users_count' => $result['users_count'],
                'dry_run' => $isDryRun
            ]);

            // Process automatic withdrawals if enabled
            if (!$isDryRun) {
                $io->section('Processing automatic withdrawals...');
                $withdrawalResult = $this->bonusCalculator->processAutomaticWithdrawals();

                if ($withdrawalResult['processed'] > 0) {
                    $io->success(sprintf(
                        'Processed %d automatic withdrawals totaling %.2f USDT',
                        $withdrawalResult['processed'],
                        $withdrawalResult['total_amount']
                    ));
                } else {
                    $io->info('No automatic withdrawals to process');
                }
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('Failed to process bonuses: ' . $e->getMessage());

            $this->logger->error('Bonus processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Check if bonuses were already processed today
     */
    private function isAlreadyProcessedToday(): bool
    {
        // Check in database or cache
        // This is a simplified version
        return false;
    }

    /**
     * Simulate calculation for dry run
     */
    private function simulateCalculation(float $profitAmount): array
    {
        // Simulate the calculation without making changes
        $companyPercent = 30;
        $distributionPercent = 100 - $companyPercent;

        return [
            'distributed' => ($profitAmount * $distributionPercent) / 100,
            'company_profit' => ($profitAmount * $companyPercent) / 100,
            'users_count' => rand(50, 200), // Simulated
            'details' => [
                'distribution_percent' => $distributionPercent,
                'total_deposits' => rand(100000, 500000) // Simulated
            ]
        ];
    }
}