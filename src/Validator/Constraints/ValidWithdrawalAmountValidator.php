<?php

namespace App\Validator\Constraints;

use App\Entity\User;
use App\Entity\Withdrawal;
use App\Repository\DepositRepository;
use App\Repository\SystemSettingsRepository;
use App\Repository\WithdrawalRepository;
use DateTime;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class ValidWithdrawalAmountValidator extends ConstraintValidator
{
    private Security $security;
    private WithdrawalRepository $withdrawalRepository;
    private DepositRepository $depositRepository;
    private SystemSettingsRepository $settingsRepository;

    public function __construct(
        Security                 $security,
        WithdrawalRepository     $withdrawalRepository,
        DepositRepository        $depositRepository,
        SystemSettingsRepository $settingsRepository
    )
    {
        $this->security = $security;
        $this->withdrawalRepository = $withdrawalRepository;
        $this->depositRepository = $depositRepository;
        $this->settingsRepository = $settingsRepository;
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidWithdrawalAmount) {
            throw new UnexpectedTypeException($constraint, ValidWithdrawalAmount::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_numeric($value)) {
            throw new UnexpectedValueException($value, 'numeric');
        }

        $amount = (float)$value;
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Get withdrawal object if we're validating in context
        $withdrawal = $this->context->getObject();
        if (!$withdrawal instanceof Withdrawal) {
            return;
        }

        // Check precision
        if ($this->getDecimalPlaces($amount) > $constraint->decimals) {
            $this->context->buildViolation($constraint->invalidPrecisionMessage)
                ->setParameter('{{ decimals }}', (string)$constraint->decimals)
                ->setCode('INVALID_PRECISION')
                ->addViolation();
            return;
        }

        // Get settings
        $settings = $this->settingsRepository->getCryptoConfig();
        $minimum = $constraint->minimum ?? $settings['withdrawal_min'] ?? 10;
        $maximum = $constraint->maximum ?? $settings['withdrawal_max'] ?? 100000;
        $dailyLimit = $constraint->dailyLimit ?? $settings['withdrawal_daily_limit'] ?? 10000;

        // Check minimum amount
        if ($amount < $minimum) {
            $this->context->buildViolation($constraint->belowMinimumMessage)
                ->setParameter('{{ minimum }}', $this->formatAmount($minimum))
                ->setParameter('{{ currency }}', $constraint->currency)
                ->setCode('BELOW_MINIMUM')
                ->addViolation();
            return;
        }

        // Check maximum amount
        if ($maximum && $amount > $maximum) {
            $this->context->buildViolation($constraint->aboveMaximumMessage)
                ->setParameter('{{ maximum }}', $this->formatAmount($maximum))
                ->setParameter('{{ currency }}', $constraint->currency)
                ->setCode('ABOVE_MAXIMUM')
                ->addViolation();
            return;
        }

        // Check balance
        if ($constraint->checkBalance) {
            $availableBalance = $this->getAvailableBalance($user, $withdrawal->getType());

            if ($amount > $availableBalance) {
                $this->context->buildViolation($constraint->insufficientBalanceMessage)
                    ->setParameter('{{ available }}', $this->formatAmount($availableBalance))
                    ->setParameter('{{ currency }}', $constraint->currency)
                    ->setCode('INSUFFICIENT_BALANCE')
                    ->addViolation();
                return;
            }
        }

        // Check daily limit
        if ($constraint->checkDailyLimit && $dailyLimit) {
            $dailyTotal = $this->withdrawalRepository->getUserDailyTotal($user, new DateTime('today'));
            $remaining = $dailyLimit - $dailyTotal;

            if ($amount > $remaining) {
                $this->context->buildViolation($constraint->dailyLimitExceededMessage)
                    ->setParameter('{{ remaining }}', $this->formatAmount($remaining))
                    ->setParameter('{{ currency }}', $constraint->currency)
                    ->setCode('DAILY_LIMIT_EXCEEDED')
                    ->addViolation();
                return;
            }
        }

        // Check lock period for deposit withdrawals
        if ($constraint->checkLockPeriod && $withdrawal->getType() === 'deposit') {
            $lockPeriod = $this->checkDepositLockPeriod($user);

            if ($lockPeriod !== null) {
                $this->context->buildViolation($constraint->lockedFundsMessage)
                    ->setParameter('{{ unlock_date }}', $lockPeriod->format('Y-m-d'))
                    ->setCode('FUNDS_LOCKED')
                    ->addViolation();
            }
        }
    }

    /**
     * Get decimal places
     */
    private function getDecimalPlaces(float $value): int
    {
        $string = (string)$value;
        $position = strpos($string, '.');

        if ($position === false) {
            return 0;
        }

        return strlen($string) - $position - 1;
    }

    /**
     * Format amount for display
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Get available balance based on withdrawal type
     */
    private function getAvailableBalance(User $user, string $type): float
    {
        return match ($type) {
            'bonus' => $user->getBonusBalance(),
            'deposit' => $user->getDepositBalance(),
            default => 0.0
        };
    }

    /**
     * Check if deposits are locked
     */
    private function checkDepositLockPeriod(User $user): ?DateTime
    {
        $firstDeposit = $this->depositRepository->findFirstDeposit($user);

        if (!$firstDeposit) {
            return null;
        }

        $unlockDate = clone $firstDeposit->getCreatedAt();
        $unlockDate->modify('+1 year');

        if ($unlockDate > new DateTime()) {
            return $unlockDate;
        }

        return null;
    }
}