<?php

namespace App\Command;

use App\Service\Bonus\BonusCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:process-bonuses',
    description: 'Calculate and distribute daily bonuses',
)]
class ProcessBonusesCommand extends Command
{
    private BonusCalculator $bonusCalculator;
    private LockFactory $lockFactory;

    public function __construct(BonusCalculator $bonusCalculator, LockFactory $lockFactory)
    {
        parent::__construct();
        $this->bonusCalculator = $bonusCalculator;
        $this->lockFactory = $lockFactory;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profit', InputArgument::REQUIRED, 'Daily profit amount in USDT');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Prevent concurrent execution
        $lock = $this->lockFactory->createLock('process-bonuses', 3600);

        if (!$lock->acquire()) {
            $io->warning('Bonus processing is already running');
            return Command::FAILURE;
        }

        try {
            $profit = $input->getArgument('profit');

            if (!is_numeric($profit) || bccomp($profit, '0', 8) <= 0) {
                $io->error('Invalid profit amount');
                return Command::INVALID;
            }

            $io->info(sprintf('Processing bonuses for profit: %s USDT', $profit));

            $bonuses = $this->bonusCalculator->calculateDailyBonuses($profit);

            $io->success(sprintf(
                'Successfully distributed bonuses to %d users',
                count($bonuses)
            ));

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}