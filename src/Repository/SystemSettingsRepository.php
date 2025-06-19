<?php

namespace App\Repository;

use App\Entity\SystemSettings;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @extends ServiceEntityRepository<SystemSettings>
 *
 * @method SystemSettings|null find($id, $lockMode = null, $lockVersion = null)
 * @method SystemSettings|null findOneBy(array $criteria, array $orderBy = null)
 * @method SystemSettings[]    findAll()
 * @method SystemSettings[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SystemSettingsRepository extends ServiceEntityRepository
{
    private CacheItemPoolInterface $cache;

    public function __construct(ManagerRegistry $registry, CacheItemPoolInterface $cache)
    {
        parent::__construct($registry, SystemSettings::class);
        $this->cache = $cache;
    }

    /**
     * Remove system setting
     */
    public function remove(SystemSettings $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
            $this->clearCache($entity->getKey());
        }
    }

    /**
     * Clear cache
     */
    private function clearCache(string $key): void
    {
        $cacheKey = 'system_setting_' . $key;
        $this->cache->deleteItem($cacheKey);
    }

    /**
     * Set setting value
     */
    public function setValue(string $key, mixed $value, ?string $type = null): void
    {
        $setting = $this->findOneBy(['key' => $key]);

        if (!$setting) {
            $setting = new SystemSettings();
            $setting->setKey($key);
        }

        if ($type === null) {
            $type = $this->detectType($value);
        }

        $setting->setValue($this->serializeValue($value, $type));
        $setting->setType($type);
        $setting->setUpdatedAt(new DateTime());

        $this->save($setting, true);
    }

    /**
     * Detect value type
     */
    private function detectType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_bool($value) => 'boolean',
            is_array($value) => 'array',
            $value instanceof DateTime => 'datetime',
            default => 'string',
        };
    }

    /**
     * Serialize value based on type
     */
    private function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'array', 'json' => json_encode($value),
            'datetime' => $value instanceof DateTime ? $value->format('Y-m-d H:i:s') : $value,
            default => (string)$value,
        };
    }

    /**
     * Save system setting
     */
    public function save(SystemSettings $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
            // Clear cache when settings are updated
            $this->clearCache($entity->getKey());
        }
    }

    /**
     * Get all settings as array
     *
     * @return array<string, mixed>
     */
    public function getAllAsArray(): array
    {
        $settings = $this->findAll();
        $result = [];

        foreach ($settings as $setting) {
            $result[$setting->getKey()] = $this->parseValue($setting->getValue(), $setting->getType());
        }

        return $result;
    }

    /**
     * Parse value based on type
     */
    private function parseValue(string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int)$value,
            'float' => (float)$value,
            'boolean' => $value === '1' || $value === 'true',
            'array', 'json' => json_decode($value, true),
            'datetime' => new DateTime($value),
            default => $value,
        };
    }

    /**
     * Get setting value by key with caching
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'system_setting_' . $key;
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        $setting = $this->findOneBy(['key' => $key]);

        if (!$setting) {
            return $default;
        }

        $value = $this->parseValue($setting->getValue(), $setting->getType());

        $item->set($value);
        $item->expiresAfter(3600); // Cache for 1 hour
        $this->cache->save($item);

        return $value;
    }

    /**
     * Get settings by category
     *
     * @return array<string, mixed>
     */
    public function getByCategory(string $category): array
    {
        $settings = $this->createQueryBuilder('s')
            ->where('s.key LIKE :category')
            ->setParameter('category', $category . '.%')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->getKey()] = $this->parseValue($setting->getValue(), $setting->getType());
        }

        return $result;
    }

    /**
     * Get crypto configuration
     *
     * @return array<string, mixed>
     */
    public function getCryptoConfig(): array
    {
        return [
            'network' => $this->getValue('crypto.network', 'TRC20'),
            'currency' => $this->getValue('crypto.currency', 'USDT'),
            'min_deposit' => $this->getValue('crypto.min_deposit', 100),
            'max_deposit' => $this->getValue('crypto.max_deposit', 100000),
            'withdrawal_min' => $this->getValue('crypto.withdrawal_min', 10),
            'withdrawal_fee' => $this->getValue('crypto.withdrawal_fee', 1),
            'hot_wallet_address' => $this->getValue('crypto.hot_wallet_address'),
            'cold_wallet_address' => $this->getValue('crypto.cold_wallet_address'),
            'hot_wallet_limit' => $this->getValue('crypto.hot_wallet_limit', 10000),
            'auto_transfer_to_cold' => $this->getValue('crypto.auto_transfer_to_cold', true),
        ];
    }

    /**
     * Get referral configuration
     *
     * @return array<string, mixed>
     */
    public function getReferralConfig(): array
    {
        return [
            'enabled' => $this->getValue('referral.enabled', true),
            'levels' => $this->getValue('referral.levels', 2),
            'level_1_percent' => $this->getValue('referral.level_1_percent', 10),
            'level_2_percent' => $this->getValue('referral.level_2_percent', 5),
            'min_deposit_for_bonus' => $this->getValue('referral.min_deposit_for_bonus', 100),
            'bonus_on_registration' => $this->getValue('referral.bonus_on_registration', false),
        ];
    }

    /**
     * Get bonus configuration
     *
     * @return array<string, mixed>
     */
    public function getBonusConfig(): array
    {
        return [
            'daily_distribution' => $this->getValue('bonus.daily_distribution', true),
            'distribution_time' => $this->getValue('bonus.distribution_time', '00:00'),
            'min_balance_for_bonus' => $this->getValue('bonus.min_balance_for_bonus', 0),
            'company_profit_percent' => $this->getValue('bonus.company_profit_percent', 30),
            'auto_withdrawal_enabled' => $this->getValue('bonus.auto_withdrawal_enabled', false),
        ];
    }
}
