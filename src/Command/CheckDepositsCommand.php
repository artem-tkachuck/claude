<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Blockchain\TronService;
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
    name: 'app:check-deposits',
    description: 'Check blockchain for new deposits',
)]
class CheckDepositsCommand extends Command
{
    private UserRepository $userRepository;
    private TronService $tronService;
    private TransactionService $transactionService;
    private LoggerInterface $logger;

    public function __construct(
        UserRepository     $userRepository,
        TronService        $tronService,
        TransactionService $transactionService,
        LoggerInterface    $logger
    )
    {
        parent::__construct();
        $this->userRepository = $userRepository;
        $this->tronService = $tronService;
        $this->transactionService = $transactionService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', 'u', InputOption::VALUE_REQUIRED, 'Check deposits for specific user')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit number of users to check', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without creating deposits')
            ->addOption('verbose-log', null, InputOption::VALUE_NONE, 'Show detailed transaction info');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getOption('user-id');
        $limit = (int)$input->getOption('limit');
        $isDryRun = $input->getOption('dry-run');
        $verboseLog = $input->getOption('verbose-log');

        $io->title('Checking Blockchain for New Deposits');

        if ($isDryRun) {
            $io->note('Running in DRY-RUN mode. No deposits will be created.');
        }

        try {
            // Get users to check
            if ($userId) {
                $users = [$this->userRepository->find($userId)];
                if (!$users[0]) {
                    $io->error('User not found');
                    return Command::FAILURE;
                }
            } else {
                $users = $this->userRepository->findUsersWithDepositAddress($limit);
            }

            if (empty($users)) {
                $io->info('No users with deposit addresses found.');
                return Command::SUCCESS;
            }

            $io->info(sprintf('Checking deposits for %d users', count($users)));

            $totalDeposits = 0;
            $totalAmount = 0;
            $errors = 0;

            $progressBar = $io->createProgressBar(count($users));
            $progressBar->start();

            foreach ($users as $user) {
                try {
                    $deposits = $this->checkUserDeposits($user, $isDryRun, $verboseLog);

                    if (!empty($deposits)) {
                        $totalDeposits += count($deposits);
                        $totalAmount += array_sum(array_column($deposits, 'amount'));

                        if ($verboseLog) {
                            $io->writeln(sprintf(
                                "\nUser %s: %d new deposits totaling %.2f USDT",
                                $user->getUsername(),
                                count($deposits),
                                array_sum(array_column($deposits, 'amount'))
                            ));
                        }
                    }

                    $progressBar->advance();

                    // Small delay to avoid rate limiting
                    usleep(100000); // 0.1 second

                } catch (Exception $e) {
                    $errors++;

                    $io->error(sprintf(
                        "\nError checking deposits for user %s: %s",
                        $user->getUsername(),
                        $e->getMessage()
                    ));

                    $this->logger->error('Failed to check user deposits', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $progressBar->finish();
            $io->newLine(2);

            // Display summary
            $io->success('Deposit checking completed!');

            $summary = [
                ['Users Checked', count($users)],
                ['New Deposits Found', $totalDeposits],
                ['Total Amount', sprintf('%.2f USDT', $totalAmount)],
                ['Errors', $errors],
            ];

            $io->table(['Metric', 'Value'], $summary);

            // Show pending deposits that need more confirmations
            $this->showPendingDeposits($io);

            return $errors > 0 ? Command::FAILURE : Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('Command failed: ' . $e->getMessage());

            $this->logger->error('Check deposits command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Check deposits for a specific user
     */
    private function checkUserDeposits($user, bool $isDryRun, bool $verboseLog): array
    {
        $depositAddress = $user->getDepositAddress();
        if (!$depositAddress) {
            return [];
        }

        // Get last deposit to know where to start checking
        $lastDeposit = $this->depositRepository->findOneBy(
            ['user' => $user, 'toAddress' => $depositAddress],
            ['createdAt' => 'DESC']
        );

        $lastTxId = $lastDeposit ? $lastDeposit->getTxHash() : null;

        // Check blockchain for new transactions
        $transactions = $this->tronService->checkIncomingTransactions($depositAddress, $lastTxId);

        $newDeposits = [];

        foreach ($transactions as $tx) {
            // Skip if transaction already exists
            $existing = $this->depositRepository->findOneBy(['txHash' => $tx['txid']]);
            if ($existing) {
                continue;
            }

            if ($verboseLog) {
                $this->logger->info('New transaction found', [
                    'user_id' => $user->getId(),
                    'txid' => $tx['txid'],
                    'amount' => $tx['amount'],
                    'confirmations' => $tx['confirmations']
                ]);
            }

            if (!$isDryRun) {
                // Create deposit
                $deposit = $this->transactionService->createDeposit($user, $tx);

                $newDeposits[] = [
                    'id' => $deposit->getId(),
                    'amount' => $deposit->getAmount(),
                    'txid' => $deposit->getTxHash(),
                    'confirmations' => $deposit->getConfirmations()
                ];
            } else {
                // Dry run - just collect info
                $newDeposits[] = [
                    'amount' => $tx['amount'],
                    'txid' => $tx['txid'],
                    'confirmations' => $tx['confirmations']
                ];
            }
        }

        return $newDeposits;
    }

    /**
     * Show pending deposits awaiting confirmations
     */
    private function showPendingDeposits(SymfonyStyle $io): void
    {
        $pendingDeposits = $this->depositRepository->findBy(['status' => 'pending']);

        if (empty($pendingDeposits)) {
            return;
        }

        $io->section('Pending Deposits Awaiting Confirmations');

        $tableData = [];
        foreach ($pendingDeposits as $deposit) {
            // Update confirmations
            $tx = $this->tronService->getTransaction($deposit->getTxHash());
            if ($tx) {
                $confirmations = $tx['confirmations'] ?? $deposit->getConfirmations();
                $deposit->setConfirmations($confirmations);

                // Check if ready to confirm
                $this->transactionService->checkDepositConfirmations($deposit);
            }

            $tableData[] = [
                $deposit->getId(),
                $deposit->getUser()->getUsername(),
                sprintf('%.2f', $deposit->getAmount()),
                $deposit->getConfirmations() . '/19',
                substr($deposit->getTxHash(), 0, 16) . '...',
                $deposit->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }

        $io->table(
            ['ID', 'User', 'Amount', 'Confirmations', 'TX Hash', 'Created'],
            $tableData
        );
    }
}