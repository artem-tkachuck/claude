<?php

namespace App\Service\Blockchain;

use App\Entity\Withdrawal;
use App\Service\Security\EncryptionService;
use Exception;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TronService implements BlockchainServiceInterface
{
    private Client $client;
    private string $apiKey;
    private string $hotWalletAddress;
    private string $coldWalletAddress;
    private float $coldWalletThreshold;
    private EncryptionService $encryption;
    private LoggerInterface $logger;

    public function __construct(
        string            $tronApiKey,
        string            $hotWalletAddress,
        string            $coldWalletAddress,
        float             $coldWalletThreshold,
        EncryptionService $encryption,
        LoggerInterface   $logger
    )
    {
        $this->client = new Client([
            'base_uri' => 'https://api.trongrid.io',
            'timeout' => 30,
            'headers' => [
                'TRON-PRO-API-KEY' => $tronApiKey,
            ],
        ]);
        $this->apiKey = $tronApiKey;
        $this->hotWalletAddress = $hotWalletAddress;
        $this->coldWalletAddress = $coldWalletAddress;
        $this->coldWalletThreshold = $coldWalletThreshold;
        $this->encryption = $encryption;
        $this->logger = $logger;
    }

    public function validateAddress(string $address): bool
    {
        try {
            // TRC20 address validation
            if (!preg_match('/^T[A-Za-z1-9]{33}$/', $address)) {
                return false;
            }

            $response = $this->client->post('/wallet/validateaddress', [
                'json' => ['address' => $address],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['result'] ?? false;
        } catch (Exception $e) {
            $this->logger->error('Address validation failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getTransaction(string $txHash): ?array
    {
        try {
            $response = $this->client->post('/wallet/gettransactionbyid', [
                'json' => ['value' => $txHash],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (empty($data)) {
                return null;
            }

            // Parse USDT transfer
            if (isset($data['raw_data']['contract'][0]['parameter']['value'])) {
                $contract = $data['raw_data']['contract'][0];
                if ($contract['type'] === 'TriggerSmartContract') {
                    $value = $contract['parameter']['value'];
                    // Decode transfer function
                    if (str_starts_with($value['data'] ?? '', 'a9059cbb')) {
                        return [
                            'hash' => $data['txID'],
                            'from' => $value['owner_address'],
                            'to' => 'T' . substr($value['data'], 32, 40),
                            'amount' => bcdiv(hexdec(substr($value['data'], 72)), '1000000', 8),
                            'confirmations' => $this->getConfirmations($data['blockNumber'] ?? 0),
                            'status' => ($data['ret'][0]['contractRet'] ?? '') === 'SUCCESS',
                        ];
                    }
                }
            }

            return null;
        } catch (Exception $e) {
            $this->logger->error('Transaction fetch failed', [
                'hash' => $txHash,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getConfirmations(int $blockNumber): int
    {
        try {
            $response = $this->client->post('/wallet/getnowblock');
            $data = json_decode($response->getBody()->getContents(), true);
            $currentBlock = $data['block_header']['raw_data']['number'] ?? 0;

            return max(0, $currentBlock - $blockNumber);
        } catch (Exception $e) {
            return 0;
        }
    }

    public function sendTransaction(Withdrawal $withdrawal): ?string
    {
        try {
            // Security check: Ensure we're not sending to suspicious addresses
            if ($this->isAddressSuspicious($withdrawal->getToAddress())) {
                throw new RuntimeException('Suspicious address detected');
            }

            // Use hot wallet for automated withdrawals
            $fromAddress = $this->hotWalletAddress;

            // Check hot wallet balance
            $balance = $this->getBalance($fromAddress);
            $totalAmount = bcadd($withdrawal->getAmount(), $withdrawal->getFee(), 8);

            if (bccomp($balance, $totalAmount, 8) < 0) {
                throw new RuntimeException('Insufficient hot wallet balance');
            }

            // In production, you would sign and broadcast the transaction
            // This is a placeholder for the actual implementation
            $this->logger->info('Withdrawal transaction prepared', [
                'withdrawal_id' => $withdrawal->getId(),
                'amount' => $withdrawal->getAmount(),
                'to' => $withdrawal->getToAddress(),
            ]);

            // Move excess funds to cold wallet if threshold exceeded
            $this->checkAndMoveToColdWallet($balance);

            // Return mock transaction hash for now
            return 'TX' . bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $this->logger->error('Transaction send failed', [
                'withdrawal_id' => $withdrawal->getId(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isAddressSuspicious(string $address): bool
    {
        // Implement blacklist checking, pattern matching, etc.
        // Check against known scam addresses, mixers, etc.
        return false;
    }

    public function getBalance(string $address): string
    {
        try {
            // USDT contract address on Tron
            $contractAddress = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

            $response = $this->client->post('/wallet/triggerconstantcontract', [
                'json' => [
                    'owner_address' => $address,
                    'contract_address' => $contractAddress,
                    'function_selector' => 'balanceOf(address)',
                    'parameter' => str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (isset($data['constant_result'][0])) {
                $balance = hexdec($data['constant_result'][0]);
                return bcdiv((string)$balance, '1000000', 8); // USDT has 6 decimals
            }

            return '0';
        } catch (Exception $e) {
            $this->logger->error('Balance check failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return '0';
        }
    }

    private function checkAndMoveToColdWallet(string $balance): void
    {
        if (bccomp($balance, (string)$this->coldWalletThreshold, 8) > 0) {
            $excessAmount = bcsub($balance, (string)($this->coldWalletThreshold * 0.5), 8);

            $this->logger->info('Moving funds to cold wallet', [
                'amount' => $excessAmount,
                'from' => $this->hotWalletAddress,
                'to' => $this->coldWalletAddress,
            ]);

            // Implement cold wallet transfer
        }
    }

    public function estimateFee(): string
    {
        // TRC20 USDT transfer typically costs ~15 TRX
        // This should be dynamic based on network conditions
        return '2.5'; // USDT fee
    }
}