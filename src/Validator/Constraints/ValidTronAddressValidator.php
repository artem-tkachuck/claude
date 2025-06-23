<?php

namespace App\Validator\Constraints;

use Exception;
use InvalidArgumentException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class ValidTronAddressValidator extends ConstraintValidator
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTronAddress) {
            throw new UnexpectedTypeException($constraint, ValidTronAddress::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // Check basic format
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $value)) {
            $this->context->buildViolation($constraint->invalidFormatMessage)
                ->setParameter('{{ value }}', $value)
                ->setCode('INVALID_FORMAT')
                ->addViolation();
            return;
        }

        // Check if it's a testnet address and if testnet is allowed
        if (!$constraint->allowTestnet && $this->isTestnetAddress($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->setCode('TESTNET_NOT_ALLOWED')
                ->addViolation();
            return;
        }

        // Validate checksum if required
        if ($constraint->checkChecksum && !$this->validateChecksum($value)) {
            $this->context->buildViolation($constraint->invalidChecksumMessage)
                ->setParameter('{{ value }}', $value)
                ->setCode('INVALID_CHECKSUM')
                ->addViolation();
        }
    }

    /**
     * Check if address is a testnet address
     */
    private function isTestnetAddress(string $address): bool
    {
        // Testnet addresses typically have different patterns
        // This is a simplified check
        return false;
    }

    /**
     * Validate TRON address checksum using Base58 decoding
     */
    private function validateChecksum(string $address): bool
    {
        try {
            $decoded = $this->base58Decode($address);

            if (strlen($decoded) !== 25) {
                return false;
            }

            // Get the payload and checksum
            $payload = substr($decoded, 0, 21);
            $checksum = substr($decoded, 21, 4);

            // Calculate expected checksum
            $hash = hash('sha256', $payload, true);
            $hash = hash('sha256', $hash, true);
            $expectedChecksum = substr($hash, 0, 4);

            return $checksum === $expectedChecksum;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Base58 decode
     */
    private function base58Decode(string $input): string
    {
        $decoded = '0';
        $multi = '1';
        $alphabet = self::ALPHABET;

        for ($i = strlen($input) - 1; $i >= 0; $i--) {
            $char = $input[$i];
            $charIndex = strpos($alphabet, $char);

            if ($charIndex === false) {
                throw new InvalidArgumentException('Invalid character in address');
            }

            $decoded = bcadd($decoded, bcmul($charIndex, $multi));
            $multi = bcmul($multi, '58');
        }

        // Convert to bytes
        $bytes = '';
        while (bccomp($decoded, '0') > 0) {
            $remainder = bcmod($decoded, '256');
            $decoded = bcdiv($decoded, '256', 0);
            $bytes = chr($remainder) . $bytes;
        }

        // Add leading zeros
        for ($i = 0; $i < strlen($input) && $input[$i] === '1'; $i++) {
            $bytes = "\x00" . $bytes;
        }

        return $bytes;
    }
}
