<?php

namespace App\Service\Security;

use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class EncryptionService
{
    private string $encryptionKey;
    private string $algorithm = 'aes-256-gcm';
    private LoggerInterface $logger;

    public function __construct(
        #[Autowire('%env(ENCRYPTION_KEY)%')] string $encryptionKey,
        LoggerInterface                             $logger
    )
    {
        $this->encryptionKey = base64_decode($encryptionKey);
        $this->logger = $logger;
    }

    /**
     * Generate encryption key
     */
    public static function generateEncryptionKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Verify hashed data
     */
    public function verifyHash(string $data, string $hash): bool
    {
        return hash_equals($hash, $this->hash($data));
    }

    /**
     * Hash sensitive data (one-way)
     */
    public function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->encryptionKey);
    }

    /**
     * Generate secure random token
     */
    public function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Encrypt array data
     *
     * @param array<string, mixed> $data
     */
    public function encryptArray(array $data): string
    {
        return $this->encrypt(json_encode($data));
    }

    /**
     * Encrypt sensitive data
     */
    public function encrypt(string $data): string
    {
        try {
            $ivLength = openssl_cipher_iv_length($this->algorithm);
            $iv = openssl_random_pseudo_bytes($ivLength);
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                $this->algorithm,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new RuntimeException('Encryption failed');
            }

            // Combine IV + tag + encrypted data
            $combined = base64_encode($iv . $tag . $encrypted);

            return $combined;
        } catch (Exception $e) {
            $this->logger->error('Encryption error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new RuntimeException('Failed to encrypt data', 0, $e);
        }
    }

    /**
     * Decrypt array data
     *
     * @return array<string, mixed>
     */
    public function decryptArray(string $encryptedData): array
    {
        $decrypted = $this->decrypt($encryptedData);
        return json_decode($decrypted, true);
    }

    /**
     * Decrypt sensitive data
     */
    public function decrypt(string $encryptedData): string
    {
        try {
            $decoded = base64_decode($encryptedData);

            $ivLength = openssl_cipher_iv_length($this->algorithm);
            $tagLength = 16; // For AES-GCM

            // Extract components
            $iv = substr($decoded, 0, $ivLength);
            $tag = substr($decoded, $ivLength, $tagLength);
            $encrypted = substr($decoded, $ivLength + $tagLength);

            $decrypted = openssl_decrypt(
                $encrypted,
                $this->algorithm,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new RuntimeException('Decryption failed');
            }

            return $decrypted;
        } catch (Exception $e) {
            $this->logger->error('Decryption error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new RuntimeException('Failed to decrypt data', 0, $e);
        }
    }

    /**
     * Mask sensitive data for logs
     */
    public function maskSensitiveData(string $data, int $visibleChars = 4): string
    {
        if (strlen($data) <= $visibleChars * 2) {
            return str_repeat('*', strlen($data));
        }

        $start = substr($data, 0, $visibleChars);
        $end = substr($data, -$visibleChars);
        $masked = str_repeat('*', strlen($data) - ($visibleChars * 2));

        return $start . $masked . $end;
    }

    /**
     * Encrypt file
     */
    public function encryptFile(string $inputPath, string $outputPath): void
    {
        $handle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');

        if (!$handle || !$outputHandle) {
            throw new RuntimeException('Failed to open file');
        }

        try {
            $ivLength = openssl_cipher_iv_length($this->algorithm);
            $iv = openssl_random_pseudo_bytes($ivLength);

            // Write IV to the beginning of the file
            fwrite($outputHandle, $iv);

            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }

                $encrypted = openssl_encrypt(
                    $chunk,
                    $this->algorithm,
                    $this->encryptionKey,
                    OPENSSL_RAW_DATA,
                    $iv
                );

                fwrite($outputHandle, $encrypted);
            }
        } finally {
            fclose($handle);
            fclose($outputHandle);
        }
    }

    /**
     * Rotate encryption key
     */
    public function rotateKey(string $newKey, array $dataToReEncrypt): void
    {
        $oldKey = $this->encryptionKey;
        $this->encryptionKey = base64_decode($newKey);

        try {
            foreach ($dataToReEncrypt as $item) {
                // Decrypt with old key
                $this->encryptionKey = $oldKey;
                $decrypted = $this->decrypt($item['encrypted_data']);

                // Encrypt with new key
                $this->encryptionKey = base64_decode($newKey);
                $reEncrypted = $this->encrypt($decrypted);

                // Update the data (implement based on your needs)
                $item['update_callback']($reEncrypted);
            }
        } catch (Exception $e) {
            // Rollback to old key on failure
            $this->encryptionKey = $oldKey;
            throw $e;
        }
    }
}
