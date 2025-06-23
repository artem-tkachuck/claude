<?php

namespace App\Validator\Constraints;

use App\Entity\Deposit;
use App\Repository\DepositRepository;
use DateTime;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueDepositTransactionValidator extends ConstraintValidator
{
    private DepositRepository $depositRepository;

    public function __construct(DepositRepository $depositRepository)
    {
        $this->depositRepository = $depositRepository;
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueDepositTransaction) {
            throw new UnexpectedTypeException($constraint, UniqueDepositTransaction::class);
        }

        if (!$value instanceof Deposit) {
            throw new UnexpectedTypeException($value, Deposit::class);
        }

        $deposit = $value;

        // Skip validation if no transaction hash
        if (!$deposit->getTxHash()) {
            return;
        }

        // Check for duplicate transaction hash
        $existing = $this->depositRepository->findOneBy(['txHash' => $deposit->getTxHash()]);

        if ($existing && $existing->getId() !== $deposit->getId()) {
            $this->context->buildViolation($constraint->duplicateTransactionMessage)
                ->setParameter('{{ tx_hash }}', $deposit->getTxHash())
                ->atPath('txHash')
                ->setCode('DUPLICATE_TRANSACTION')
                ->addViolation();
            return;
        }

        // Check for recent similar deposits if enabled
        if ($constraint->checkRecentDuplicates) {
            $this->checkRecentDuplicates($deposit, $constraint);
        }
    }

    /**
     * Check for recent duplicate deposits
     */
    private function checkRecentDuplicates(Deposit $deposit, UniqueDepositTransaction $constraint): void
    {
        $since = new DateTime();
        $since->modify("-{$constraint->recentThresholdSeconds} seconds");

        $recentDeposits = $this->depositRepository->findRecentByUser(
            $deposit->getUser(),
            $constraint->recentThresholdSeconds / 86400 // Convert to days
        );

        foreach ($recentDeposits as $recentDeposit) {
            // Skip if it's the same deposit
            if ($recentDeposit->getId() === $deposit->getId()) {
                continue;
            }

            // Check if it's a duplicate
            if ($this->isDuplicate($deposit, $recentDeposit)) {
                $this->context->buildViolation($constraint->recentDuplicateMessage)
                    ->setCode('RECENT_DUPLICATE')
                    ->addViolation();
                return;
            }
        }
    }

    /**
     * Check if two deposits are duplicates
     */
    private function isDuplicate(Deposit $deposit1, Deposit $deposit2): bool
    {
        // Same amount
        if (abs($deposit1->getAmount() - $deposit2->getAmount()) > 0.01) {
            return false;
        }

        // Same from address
        if ($deposit1->getFromAddress() !== $deposit2->getFromAddress()) {
            return false;
        }

        // Same to address
        if ($deposit1->getToAddress() !== $deposit2->getToAddress()) {
            return false;
        }

        // Check time difference
        $timeDiff = abs(
            $deposit1->getCreatedAt()->getTimestamp() -
            $deposit2->getCreatedAt()->getTimestamp()
        );

        // If less than threshold, consider it a duplicate
        return $timeDiff < 60; // 60 seconds
    }
}
