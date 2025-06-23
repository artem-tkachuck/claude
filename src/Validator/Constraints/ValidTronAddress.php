<?php

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ValidTronAddress extends Constraint
{
    public string $message = 'The address "{{ value }}" is not a valid TRON address.';
    public string $invalidFormatMessage = 'TRON addresses must start with "T" and be 34 characters long.';
    public string $invalidChecksumMessage = 'The address has an invalid checksum.';
    public bool $checkChecksum = true;
    public bool $allowTestnet = false;

    public function __construct(
        ?array  $options = null,
        ?string $message = null,
        ?string $invalidFormatMessage = null,
        ?string $invalidChecksumMessage = null,
        ?bool   $checkChecksum = null,
        ?bool   $allowTestnet = null,
        ?array  $groups = null,
        mixed   $payload = null
    )
    {
        parent::__construct($options ?? [], $groups, $payload);

        $this->message = $message ?? $this->message;
        $this->invalidFormatMessage = $invalidFormatMessage ?? $this->invalidFormatMessage;
        $this->invalidChecksumMessage = $invalidChecksumMessage ?? $this->invalidChecksumMessage;
        $this->checkChecksum = $checkChecksum ?? $this->checkChecksum;
        $this->allowTestnet = $allowTestnet ?? $this->allowTestnet;
    }
}