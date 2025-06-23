<?php

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ValidWithdrawalAmount extends Constraint
{
    public string $message = 'The withdrawal amount is invalid.';
    public string $insufficientBalanceMessage = 'Insufficient balance. Available: {{ available }} {{ currency }}.';
    public string $belowMinimumMessage = 'Minimum withdrawal amount is {{ minimum }} {{ currency }}.';
    public string $aboveMaximumMessage = 'Maximum withdrawal amount is {{ maximum }} {{ currency }}.';
    public string $dailyLimitExceededMessage = 'Daily withdrawal limit exceeded. Remaining limit: {{ remaining }} {{ currency }}.';
    public string $lockedFundsMessage = 'These funds are locked until {{ unlock_date }}.';
    public string $invalidPrecisionMessage = 'Amount must have maximum {{ decimals }} decimal places.';

    public ?float $minimum = null;
    public ?float $maximum = null;
    public ?float $dailyLimit = null;
    public string $currency = 'USDT';
    public int $decimals = 2;
    public bool $checkBalance = true;
    public bool $checkDailyLimit = true;
    public bool $checkLockPeriod = true;

    public function __construct(
        ?array  $options = null,
        ?string $message = null,
        ?float  $minimum = null,
        ?float  $maximum = null,
        ?float  $dailyLimit = null,
        ?string $currency = null,
        ?int    $decimals = null,
        ?bool   $checkBalance = null,
        ?bool   $checkDailyLimit = null,
        ?bool   $checkLockPeriod = null,
        ?array  $groups = null,
        mixed   $payload = null
    )
    {
        parent::__construct($options ?? [], $groups, $payload);

        $this->message = $message ?? $this->message;
        $this->minimum = $minimum ?? $this->minimum;
        $this->maximum = $maximum ?? $this->maximum;
        $this->dailyLimit = $dailyLimit ?? $this->dailyLimit;
        $this->currency = $currency ?? $this->currency;
        $this->decimals = $decimals ?? $this->decimals;
        $this->checkBalance = $checkBalance ?? $this->checkBalance;
        $this->checkDailyLimit = $checkDailyLimit ?? $this->checkDailyLimit;
        $this->checkLockPeriod = $checkLockPeriod ?? $this->checkLockPeriod;
    }

    public function getTargets(): string|array
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
