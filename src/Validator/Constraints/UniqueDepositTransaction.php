<?php

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"CLASS"})
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UniqueDepositTransaction extends Constraint
{
    public string $message = 'This transaction has already been processed.';
    public string $duplicateTransactionMessage = 'Transaction with hash "{{ tx_hash }}" already exists.';
    public string $recentDuplicateMessage = 'A similar transaction was processed recently. Please wait before retrying.';

    public int $recentThresholdSeconds = 60;
    public bool $checkRecentDuplicates = true;

    public function __construct(
        ?array  $options = null,
        ?string $message = null,
        ?string $duplicateTransactionMessage = null,
        ?string $recentDuplicateMessage = null,
        ?int    $recentThresholdSeconds = null,
        ?bool   $checkRecentDuplicates = null,
        ?array  $groups = null,
        mixed   $payload = null
    )
    {
        parent::__construct($options ?? [], $groups, $payload);

        $this->message = $message ?? $this->message;
        $this->duplicateTransactionMessage = $duplicateTransactionMessage ?? $this->duplicateTransactionMessage;
        $this->recentDuplicateMessage = $recentDuplicateMessage ?? $this->recentDuplicateMessage;
        $this->recentThresholdSeconds = $recentThresholdSeconds ?? $this->recentThresholdSeconds;
        $this->checkRecentDuplicates = $checkRecentDuplicates ?? $this->checkRecentDuplicates;
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}