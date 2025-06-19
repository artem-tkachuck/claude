<?php

namespace App\Command;

use App\Repository\EventLogRepository;
use App\Repository\TransactionRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:cleanup',
    description: 'Clean up old logs, temporary files, and database records',
)]
class CleanupCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private EventLogRepository $eventLogRepository;
    private TransactionRepository $transactionRepository;
    private LoggerInterface $logger;
    private string $projectDir;
    private string $cacheDir;
    private string $logDir;

    public function __construct(
        EntityManagerInterface $entityManager,
        EventLogRepository     $eventLogRepository,
        TransactionRepository  $transactionRepository,
        LoggerInterface        $logger,
        string                 $projectDir,
        string                 $cacheDir,
        string                 $logDir
    )
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->eventLogRepository = $eventLogRepository;
        $this->transactionRepository = $transactionRepository;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->cacheDir = $cacheDir;
        $this->logDir = $logDir;
    }

    protected function configure(): void
    {
        $this
            ->addOption('logs', null, InputOption::VALUE_NONE, 'Clean old log files')
            ->addOption('cache', null, InputOption::VALUE_NONE, 'Clear cache files')
            ->addOption('events', null, InputOption::VALUE_NONE, 'Clean old event logs from database')
            ->addOption('sessions', null, InputOption::VALUE_NONE, 'Clean expired sessions')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Clean everything')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Number of days to keep', 90)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cleanLogs = $input->getOption('logs');
        $cleanCache = $input->getOption('cache');
        $cleanEvents = $input->getOption('events');
        $cleanSessions = $input->getOption('sessions');
        $cleanAll = $input->getOption('all');
        $daysToKeep = (int)$input->getOption('days');
        $isDryRun = $input->getOption('dry-run');

        if ($cleanAll) {
            $cleanLogs = $cleanCache = $cleanEvents = $cleanSessions = true;
        }

        if (!$cleanLogs && !$cleanCache && !$cleanEvents && !$cleanSessions) {
            $io->error('No cleanup option selected. Use --logs, --cache, --events, --sessions, or --all');
            return Command::FAILURE;
        }

        $io->title('System Cleanup');

        if ($isDryRun) {
            $io->note('Running in DRY-RUN mode. No files or records will be deleted.');
        }

        $totalCleaned = 0;
        $totalSize = 0;

        try {
            // Clean log files
            if ($cleanLogs) {
                $io->section('Cleaning Log Files');
                $result = $this->cleanLogFiles($daysToKeep, $isDryRun);
                $totalCleaned += $result['count'];
                $totalSize += $result['size'];

                $io->success(sprintf(
                    'Cleaned %d log files (%.2f MB)',
                    $result['count'],
                    $result['size'] / 1024 / 1024
                ));
            }

            // Clean cache
            if ($cleanCache) {
                $io->section('Cleaning Cache');
                $result = $this->cleanCache($isDryRun);
                $totalCleaned += $result['count'];
                $totalSize += $result['size'];

                $io->success(sprintf(
                    'Cleaned %d cache files (%.2f MB)',
                    $result['count'],
                    $result['size'] / 1024 / 1024
                ));
            }

            // Clean event logs
            if ($cleanEvents) {
                $io->section('Cleaning Event Logs');
                $result = $this->cleanEventLogs($daysToKeep, $isDryRun);
                $totalCleaned += $result['count'];

                $io->success(sprintf('Cleaned %d event log records', $result['count']));
            }

            // Clean sessions
            if ($cleanSessions) {
                $io->section('Cleaning Sessions');
                $result = $this->cleanSessions($isDryRun);
                $totalCleaned += $result['count'];

                $io->success(sprintf('Cleaned %d expired sessions', $result['count']));
            }

            // Display summary
            $io->section('Cleanup Summary');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Items Cleaned', $totalCleaned],
                    ['Total Space Freed', sprintf('%.2f MB', $totalSize / 1024 / 1024)],
                    ['Dry Run', $isDryRun ? 'Yes' : 'No'],
                ]
            );

            $this->logger->info('System cleanup completed', [
                'items_cleaned' => $totalCleaned,
                'space_freed' => $totalSize,
                'dry_run' => $isDryRun
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $io->error('Cleanup failed: ' . $e->getMessage());

            $this->logger->error('Cleanup command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Clean old log files
     */
    private function cleanLogFiles(int $daysToKeep, bool $isDryRun): array
    {
        $filesystem = new Filesystem();
        $count = 0;
        $totalSize = 0;

        $cutoffDate = new DateTime("-{$daysToKeep} days");

        // Patterns for log files to clean
        $patterns = [
            $this->logDir . '/*.log',
            $this->logDir . '/prod-*.log',
            $this->logDir . '/dev-*.log',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);

            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $fileDate = new DateTime();
                $fileDate->setTimestamp(filemtime($file));

                if ($fileDate < $cutoffDate) {
                    $size = filesize($file);

                    if (!$isDryRun) {
                        $filesystem->remove($file);
                    }

                    $count++;
                    $totalSize += $size;

                    $this->logger->info('Cleaned log file', [
                        'file' => basename($file),
                        'size' => $size,
                        'date' => $fileDate->format('Y-m-d')
                    ]);
                }
            }
        }

        // Rotate current logs if they're too large
        $this->rotateCurrentLogs($isDryRun);

        return ['count' => $count, 'size' => $totalSize];
    }

    /**
     * Rotate current logs if they're too large
     */
    private function rotateCurrentLogs(bool $isDryRun): void
    {
        $maxSize = 100 * 1024 * 1024; // 100 MB

        $currentLogs = [
            $this->logDir . '/prod.log',
            $this->logDir . '/dev.log',
        ];

        foreach ($currentLogs as $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }

            $size = filesize($logFile);
            if ($size > $maxSize) {
                if (!$isDryRun) {
                    $rotatedFile = $logFile . '.' . date('Y-m-d-His');
                    rename($logFile, $rotatedFile);

                    // Compress rotated file
                    $gz = gzopen($rotatedFile . '.gz', 'w9');
                    gzwrite($gz, file_get_contents($rotatedFile));
                    gzclose($gz);
                    unlink($rotatedFile);
                }

                $this->logger->info('Rotated large log file', [
                    'file' => basename($logFile),
                    'size' => $size
                ]);
            }
        }
    }

    /**
     * Clean cache files
     */
    private function cleanCache(bool $isDryRun): array
    {
        $filesystem = new Filesystem();
        $count = 0;
        $totalSize = 0;

        // Cache directories to clean
        $cacheDirs = [
            $this->cacheDir . '/pools',
            $this->cacheDir . '/profiler',
            $this->projectDir . '/var/cache/test',
        ];

        foreach ($cacheDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size = $file->getSize();

                    if (!$isDryRun) {
                        $filesystem->remove($file->getPathname());
                    }

                    $count++;
                    $totalSize += $size;
                }
            }
        }

        return ['count' => $count, 'size' => $totalSize];
    }

    /**
     * Clean old event logs from database
     */
    private function cleanEventLogs(int $daysToKeep, bool $isDryRun): array
    {
        if ($isDryRun) {
            // Count records that would be deleted
            $cutoffDate = new DateTime("-{$daysToKeep} days");
            $count = $this->eventLogRepository->count([
                'createdAt' => ['$lt' => $cutoffDate]
            ]);

            return ['count' => $count];
        }

        $count = $this->eventLogRepository->cleanOldLogs($daysToKeep);

        $this->logger->info('Cleaned event logs', [
            'count' => $count,
            'days_kept' => $daysToKeep
        ]);

        return ['count' => $count];
    }

    /**
     * Clean expired sessions
     */
    private function cleanSessions(bool $isDryRun): array
    {
        $count = 0;

        // Clean database sessions if used
        $conn = $this->entityManager->getConnection();

        try {
            if ($isDryRun) {
                $sql = "SELECT COUNT(*) FROM sessions WHERE sess_time < :time";
                $stmt = $conn->prepare($sql);
                $stmt->execute(['time' => time() - 86400]); // 24 hours
                $count = $stmt->fetchOne();
            } else {
                $sql = "DELETE FROM sessions WHERE sess_time < :time";
                $stmt = $conn->prepare($sql);
                $stmt->execute(['time' => time() - 86400]);
                $count = $stmt->rowCount();
            }
        } catch (Exception $e) {
            // Sessions table might not exist
            $this->logger->warning('Could not clean sessions', ['error' => $e->getMessage()]);
        }

        // Clean file-based sessions
        $sessionDir = $this->projectDir . '/var/sessions';
        if (is_dir($sessionDir)) {
            $files = glob($sessionDir . '/sess_*');
            foreach ($files as $file) {
                if (filemtime($file) < time() - 86400) {
                    if (!$isDryRun) {
                        unlink($file);
                    }
                    $count++;
                }
            }
        }

        return ['count' => $count];
    }
}