<?php

namespace App\Service\Blockchain;

use App\Repository\DepositRepository;
use App\Repository\SystemSettingsRepository;
use App\Service\Security\EncryptionService;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TronService
{
    private HttpClientInterface $httpClient;
    private EncryptionService $encryptionService;
    private SystemSettingsRepository $settingsRepository;
    private DepositRepository $depositRepository;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $apiUrl;
    private array $networkConfig;

    public function __construct(
        HttpClientInterface                       $httpClient,
        EncryptionService                         $encryptionService,
        SystemSettingsRepository                  $settingsRepository,
        DepositRepository                         $depositRepository,
        LoggerInterface                           $logger,
        #[Autowire('%env(TRON_API_KEY)%')] string $apiKey,
        #[Autowire('%env(TRON_API_URL)%')] string $apiUrl
    )
    {
        $this->httpClient = $httpClient;
        $this->encryptionService = $encryptionService;
        $this->settingsRepository = $settingsRepository;
        $this->depositRepository = $depositRepository;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;

        $this->networkConfig = [
            'TRC20' => [
                'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', // USDT TRC20
                'decimals' => 6,
                'min_confirmations' => 19
            ]
        ];
    }

    /**
     * Generate new deposit address
     */
    public function generateDepositAddress(): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/v1/wallet/generateaddress', [
                'headers' => [
                    'TRON-PRO-API-KEY' => $this->apiKey,
                ],
                'json' => [
                    'network' => 'tron'
                ]
            ]);

            $data = $response->toArray();

            if (!isset($data['address']) || !isset($data['privateKey'])) {
                throw new RuntimeException('Invalid response from Tron API');
            }

            // Encrypt private key before storing
            $encryptedPrivateKey = $this->encryptionService->encrypt($data['privateKey']);

            $this->logger->info('New deposit address generated', [
                'address' => $data['address']
            ]);

            return [
                'address' => $data['address'],
                'privateKey' => $encryptedPrivateKey,
                'hexAddress' => $data['hexAddress'] ?? null
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to generate deposit address', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check address balance
     */
    public function getBalance(string $address): float
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/v1/accounts/' . $address, [
                'headers' => [
                    'TRON-PRO-API-KEY' => $this->apiKey,
                ]
            ]);

            $data = $response->toArray();

            // Get TRX balance
            $trxBalance = ($data['balance'] ?? 0) / 1000000;

            // Get USDT balance
            $usdtBalance = $this->getTokenBalance($address, $this->networkConfig['TRC20']['contract']);

            return $usdtBalance;
        } catch (Exception $e) {
            $this->logger->error('Failed to get balance', [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get token balance (USDT)
     */
    public function getTokenBalance(string $address, string $contractAddress): float
    {
        try {
            $response = $this->httpClient->request('GET',
                $this->apiUrl . '/v1/accounts/' . $address . '/tokens/' . $contractAddress, [
                    'headers' => [
                        'TRON-PRO-API-KEY' => $this->apiKey,
                    ]
                ]);

            $data = $response->toArray();
            $balance = $data['balance'] ?? '0';

            // Convert from smallest unit to USDT
            return floatval($balance) / pow(10, $this->networkConfig['TRC20']['decimals']);
        } catch (Exception $e) {
            $this->logger->error('Failed to get token balance', [
                'address' => $address,
                'contract' => $contractAddress,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Monitor incoming transactions
     */
    public function checkIncomingTransactions(string $address, ?string $lastTxId = null): array
    {
        try {
            $params = [
                'only_to' => true,
                'limit' => 50,
                'order_by' => 'block_timestamp,desc'
            ];

            if ($lastTxId) {
                $params['min_timestamp'] = $this->getTransactionTimestamp($lastTxId) + 1;
            }

            $response = $this->httpClient->request('GET',
                $this->apiUrl . '/v1/accounts/' . $address . '/transactions/trc20', [
                    'headers' => [
                        'TRON-PRO-API-KEY' => $this->apiKey,
                    ],
                    'query' => $params
                ]);

            $data = $response->toArray();
            $transactions = $data['data'] ?? [];

            $processedTxs = [];

            foreach ($transactions as $tx) {
                // Only process USDT transactions
                if ($tx['token_info']['address'] !== $this->networkConfig['TRC20']['contract']) {
                    continue;
                }

                $amount = floatval($tx['value']) / pow(10, $tx['token_info']['decimals']);

                $processedTxs[] = [
                    'txid' => $tx['transaction_id'],
                    'from' => $tx['from'],
                    'to' => $tx['to'],
                    'amount' => $amount,
                    'timestamp' => $tx['block_timestamp'] / 1000,
                    'confirmations' => $this->getConfirmations($tx['block_number'] ?? 0),
                    'token' => $tx['token_info']['symbol']
                ];
            }

            return $processedTxs;
        } catch (Exception $e) {
            $this->logger->error('Failed to check incoming transactions', [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get transaction timestamp
     */
    private function getTransactionTimestamp(string $txId): int
    {
        $tx = $this->getTransaction($txId);
        return $tx['timestamp'] ?? 0;
    }

    /**
     * Get transaction details
     */
    public function getTransaction(string $txId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/v1/transactions/' . $txId, [
                'headers' => [
                    'TRON-PRO-API-KEY' => $this->apiKey,
                ]
            ]);

            $data = $response->toArray();

            if (empty($data)) {
                return null;
            }

            return [
                'txid' => $data['txID'],
                'status' => $data['ret'][0]['contractRet'] ?? 'UNKNOWN',
                'block' => $data['blockNumber'] ?? null,
                'timestamp' => $data['blockTimeStamp'] ?? null,
                'fee' => ($data['fee'] ?? 0) / 1000000,
                'data' => $data
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get transaction', [
                'txid' => $txId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Calculate confirmations
     */
    private function getConfirmations(int $blockNumber): int
    {
        if ($blockNumber === 0) {
            return 0;
        }

        $currentBlock = $this->getCurrentBlock();
        return max(0, $currentBlock - $blockNumber);
    }

    /**
     * Get current block number
     */
    private function getCurrentBlock(): int
    {
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/v1/blocks/latest', [
                'headers' => [
                    'TRON-PRO-API-KEY' => $this->apiKey,
                ]
            ]);

            $data = $response->toArray();
            return $data['block_header']['raw_data']['number'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Validate Tron address
     */
    public function validateAddress(string $address): bool
    {
        // Check if address starts with T and is 34 characters
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        try {
            // Verify with API
            $response = $this->httpClient->request('POST', $this->apiUrl . '/wallet/validateaddress', [
                'headers' => [
                    'TRON-PRO-API-KEY' => $this->apiKey,
                ],
                'json' => [
                    'address' => $address
                ]
            ]);

            $data = $response->toArray();
            return $data['result'] ?? false;
        } catch (Exception $e) {
            $this->logger->warning('Address validation failed', [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Estimate transaction fee
     */
    public function estimateFee(string $from, string $to, float $amount): float
    {
        // TRC20 transfer typically costs 10-30 TRX
        // This is a simplified estimation
        return 15.0; // TRX
    }

    /**
     * Transfer to cold wallet
     */
    public function transferToColdWallet(float $amount): bool
    {
        try {
            $hotWallet = $this->settingsRepository->getValue('crypto.hot_wallet_address');
            $coldWallet = $this->settingsRepository->getValue('crypto.cold_wallet_address');
            $privateKey = $this->settingsRepository->getValue('crypto.hot_wallet_private_key');

            if (!$hotWallet || !$coldWallet || !$privateKey) {
                throw new RuntimeException('Wallet configuration missing');
            }

            $result = $this->sendUsdt($hotWallet, $coldWallet, $amount, $privateKey);

            $this->logger->info('Transfer to cold wallet completed', [
                'amount' => $amount,
                'txid' => $result['txid']
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to transfer to cold wallet', [
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send USDT transaction
     */
    public function sendUsdt(string $fromAddress, string $toAddress, float $amount, string $privateKey): array
    {
        try {
            $decryptedKey = $this->encryptionService->decrypt($privateKey);

            // Convert amount to smallest unit
            $value = bcmul((string)$amount, (string)pow(10, $this->networkConfig['TRC20']['decimals']));

            // Build transaction
            $response = $this->httpClient->request('POST', $this->apiUrl . '/v1/trc20/transfer', [
                'headers' => [
                    'TRON-PRO-API-KEY' => $this->apiKey,
                ],
                'json' => [
                    'owner_address' => $fromAddress,
                    'to_address' => $toAddress,
                    'contract_address' => $this->networkConfig['TRC20']['contract'],
                    'amount' => $value,
                    'private_key' => $decryptedKey,
                    'fee_limit' => 100000000 // 100 TRX max fee
                ]
            ]);

            $data = $response->toArray();

            if (!isset($data['txid'])) {
                throw new RuntimeException('Transaction failed: ' . ($data['error'] ?? 'Unknown error'));
            }

            $this->logger->info('USDT transaction sent', [
                'txid' => $data['txid'],
                'from' => $fromAddress,
                'to' => $toAddress,
                'amount' => $amount
            ]);

            return [
                'txid' => $data['txid'],
                'success' => true
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to send USDT', [
                'from' => $fromAddress,
                'to' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}