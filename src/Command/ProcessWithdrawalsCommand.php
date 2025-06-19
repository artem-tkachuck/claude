<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\WithdrawalRepository;
use App\Service\Transaction\TransactionService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-withdrawals',
    description: 'Process approved withdrawals automatically',
)]
class ProcessWithdrawalsCommand extends Command
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
        parent::__construct();
        $this->withdrawalRepository = $withdrawalRepository;
        $this->transactionService = $transactionService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of withdrawals to process', 10)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without executing withdrawals')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Process only specific type (bonus/deposit)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int)$input->getOption('limit');
        $isDryRun = $input->getOption('dry-run');
        $type = $input->getOption('type');

        $io->title('Processing Withdrawals');

        if ($isDryRun) {
            $io->note('Running in DRY-RUN mode. No actual transactions will be executed.');
        }

        // Get withdrawals ready for processing (approved by 2 admins)
        $criteria = ['status' => 'pending'];
        if ($type) {
            $criteria['type'] = $type;
        }

        $withdrawals = $this->withdrawalRepository->findReadyForProcessing($criteria, $limit);

        if (empty($withdrawals)) {
            $io->info('No withdrawals ready for processing.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d withdrawals ready for processing', count($withdrawals)));

        $processed = 0;
        $failed = 0;
        $totalAmount = 0;

        $progressBar = $io->createProgressBar(count($withdrawals));
        $progressBar->start();

        foreach ($withdrawals as $withdrawal) {
            try {
                if ($isDryRun) {
                    $io->writeln(sprintf(
                        "\nWould process: ID %d, User: %s, Amount: %.2f USDT, Address: %s",
                        $withdrawal->getId(),
                        $withdrawal->getUser()->getUsername(),
                        $withdrawal->getAmount(),
                        $withdrawal->getAddress()
                    ));
                } else {
                    // Create a system admin user for automatic processing
                    $systemAdmin = $this->createSystemAdmin();

                    // Process the withdrawal
                    $this->transactionService->processWithdrawal($withdrawal, $systemAdmin);

                    $processed++;
                    $totalAmount += $withdrawal->getAmount();

                    $this->logger->info('Withdrawal processed automatically', [
                        'withdrawal_id' => $withdrawal->getId(),
                        'amount' => $withdrawal->getAmount(),
                        'user_id' => $withdrawal->getUser()->getId()
                    ]);
                }

                $progressBar->advance();

            } catch (Exception $e) {
                $failed++;

                $io->error(sprintf(
                    "\nFailed to process withdrawal ID %d: %s",
                    $withdrawal->getId(),
                    $e->getMessage()
                ));

                $this->logger->error('Failed to process withdrawal', [
                    'withdrawal_id' => $withdrawal->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Display summary
        $io->success('Withdrawal processing completed!');

        $summary = [
            ['Total Withdrawals', count($withdrawals)],
            ['Successfully Processed', $processed],
            ['Failed', $failed],
            ['Total Amount', sprintf('%.2f USDT', $totalAmount)],
        ];

        $io->table(['Metric', 'Value'], $summary);

        // Check hot wallet balance
        $this->checkHotWalletBalance($io);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Create system admin user for automatic processing
     */
    private function createSystemAdmin(): User
    {
        $admin = new User();
        $admin->setId(0); // System user
        $admin->setUsername('system');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_SYSTEM']);

        return $admin;
    }

    /**
     * Check hot wallet balance
     */
    private function checkHotWalletBalance(SymfonyStyle $io): void
    {
        try {
            $hotWalletAddress = $_ENV['HOT_WALLET_ADDRESS'] ?? '';
            if (!$hotWalletAddress) {
                return;
            }

            $balance = $this->tronService->getBalance($hotWalletAddress);
            $pendingTotal = $this->withdrawalRepository->getPendingTotal();

            $io->section('Hot Wallet Status');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Current Balance', sprintf('%.2f USDT', $balance)],
                    ['Pending Withdrawals', sprintf('%.2f USDT', $pendingTotal)],
                    ['Available', sprintf('%.2f USDT', $balance - $pendingTotal)],
                ]
            );

            if ($balance < $pendingTotal) {
                $io->warning('Hot wallet balance is insufficient for pending withdrawals!');
            } elseif ($balance < ($pendingTotal * 1.5)) {
                $io->caution('Hot wallet balance is running low.');
            }

        } catch (Exception $e) {
            $io->error('Failed to check hot wallet balance: ' . $e->getMessage());
        }
    }
}
