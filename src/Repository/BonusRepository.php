<?php

namespace App\Repository;

use App\Entity\Bonus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bonus>
 *
 * @method Bonus|null find($id, $lockMode = null, $lockVersion = null)
 * @method Bonus|null findOneBy(array $criteria, array $orderBy = null)
 * @method Bonus[]    findAll()
 * @method Bonus[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BonusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bonus::class);
    }

    public function save(Bonus $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Bonus $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find bonuses by user
     *
     * @return Bonus[]
     */
    public function findByUser(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find bonuses by type
     *
     * @return Bonus[]
     */
    public function findByType(string $type, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.type = :type')
            ->setParameter('type', $type)
            ->orderBy('b.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find bonuses by date
     *
     * @return Bonus[]
     */
    public function findByDate(\DateTimeInterface $date): array
    {
        $startOfDay = clone $date;
        if ($startOfDay instanceof \DateTime) {
            $startOfDay->setTime(0, 0, 0);
        } else {
            $startOfDay = new \DateTimeImmutable($date->format('Y-m-d 00:00:00'));
        }

        $endOfDay = clone $date;
        if ($endOfDay instanceof \DateTime) {
            $endOfDay->setTime(23, 59, 59);
        } else {
            $endOfDay = new \DateTimeImmutable($date->format('Y-m-d 23:59:59'));
        }

        return $this->createQueryBuilder('b')
            ->andWhere('b.bonusDate BETWEEN :start AND :end')
            ->setParameter('start', $startOfDay)
            ->setParameter('end', $endOfDay)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find bonuses for distribution
     *
     * @return Bonus[]
     */
    public function findForDistribution(): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.transaction IS NULL')
            ->setParameter('status', Bonus::STATUS_CALCULATED)
            ->orderBy('b.calculatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find today's daily bonuses
     *
     * @return Bonus[]
     */
    public function findTodaysDailyBonuses(): array
    {
        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('b')
            ->andWhere('b.type = :type')
            ->andWhere('b.bonusDate = :date')
            ->setParameter('type', Bonus::TYPE_DAILY)
            ->setParameter('date', $today)
            ->orderBy('b.amount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if user has received daily bonus for date
     */
    public function hasUserReceivedDailyBonus(User $user, \DateTimeInterface $date): bool
    {
        $count = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.user = :user')
            ->andWhere('b.type = :type')
            ->andWhere('b.bonusDate = :date')
            ->setParameter('user', $user)
            ->setParameter('type', Bonus::TYPE_DAILY)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Get user bonus statistics
     */
    public function getUserStatistics(User $user): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->setParameter('user', $user);

        // Total bonuses by type
        $typeStats = (clone $qb)
            ->select('b.type, COUNT(b.id) as count, SUM(b.amount) as total')
            ->groupBy('b.type')
            ->getQuery()
            ->getResult();

        $stats = [
            'by_type' => [],
            'total_count' => 0,
            'total_amount' => '0',
        ];

        foreach ($typeStats as $typeStat) {
            $stats['by_type'][$typeStat['type']] = [
                'count' => $typeStat['count'],
                'total' => $typeStat['total'] ?? '0',
            ];
            $stats['total_count'] += $typeStat['count'];
            $stats['total_amount'] = bcadd($stats['total_amount'], $typeStat['total'] ?? '0', 8);
        }

        // Daily average
        $firstBonus = (clone $qb)
            ->select('MIN(b.createdAt) as first_date')
            ->getQuery()
            ->getSingleScalarResult();

        if ($firstBonus) {
            $days = (new \DateTime($firstBonus))->diff(new \DateTime())->days + 1;
            $stats['daily_average'] = bcdiv($stats['total_amount'], (string)$days, 8);
        } else {
            $stats['daily_average'] = '0';
        }

        return $stats;
    }

    /**
     * Get referral bonus statistics
     */
    public function getReferralStatistics(User $user): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->andWhere('b.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', Bonus::TYPE_REFERRAL);

        // By level
        $levelStats = (clone $qb)
            ->select('b.referralLevel as level, COUNT(b.id) as count, SUM(b.amount) as total')
            ->groupBy('b.referralLevel')
            ->orderBy('b.referralLevel', 'ASC')
            ->getQuery()
            ->getResult();

        $stats = [
            'by_level' => [],
            'total_count' => 0,
            'total_amount' => '0',
        ];

        foreach ($levelStats as $levelStat) {
            $level = $levelStat['level'] ?? 0;
            $stats['by_level'][$level] = [
                'count' => $levelStat['count'],
                'total' => $levelStat['total'] ?? '0',
            ];
            $stats['total_count'] += $levelStat['count'];
            $stats['total_amount'] = bcadd($stats['total_amount'], $levelStat['total'] ?? '0', 8);
        }

        // Unique referrals
        $stats['unique_referrals'] = (clone $qb)
            ->select('COUNT(DISTINCT b.referralFrom)')
            ->getQuery()
            ->getSingleScalarResult();

        return $stats;
    }

    /**
     * Get bonus distribution statistics for date range
     */
    public function getDistributionStatistics(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.bonusDate BETWEEN :from AND :to')
            ->andWhere('b.type = :type')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('type', Bonus::TYPE_DAILY);

        $dailyStats = (clone $qb)
            ->select(
                'b.bonusDate as date',
                'COUNT(b.id) as user_count',
                'SUM(b.amount) as total_distributed',
                'AVG(b.amount) as average_bonus',
                'MAX(b.amount) as max_bonus',
                'MIN(b.amount) as min_bonus'
            )
            ->groupBy('b.bonusDate')
            ->orderBy('b.bonusDate', 'DESC')
            ->getQuery()
            ->getResult();

        $totalStats = (clone $qb)
            ->select(
                'COUNT(b.id) as total_bonuses',
                'COUNT(DISTINCT b.user) as unique_users',
                'SUM(b.amount) as total_amount'
            )
            ->getQuery()
            ->getSingleResult();

        return [
            'daily' => $dailyStats,
            'summary' => [
                'total_bonuses' => $totalStats['total_bonuses'] ?? 0,
                'unique_users' => $totalStats['unique_users'] ?? 0,
                'total_amount' => $totalStats['total_amount'] ?? '0',
                'days' => count($dailyStats),
            ],
        ];
    }

    /**
     * Find bonuses by batch ID
     *
     * @return Bonus[]
     */
    public function findByBatchId(int $batchId): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.batchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->orderBy('b.amount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get next batch ID
     */
    public function getNextBatchId(): int
    {
        $result = $this->createQueryBuilder('b')
            ->select('MAX(b.batchId) as max_batch')
            ->getQuery()
            ->getSingleScalarResult();

        return ($result ?? 0) + 1;
    }

    /**
     * Find failed bonuses
     *
     * @return Bonus[]
     */
    public function findFailedBonuses(int $days = 7): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.createdAt >= :since')
            ->setParameter('status', Bonus::STATUS_FAILED)
            ->setParameter('since', $since)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Create query builder for pagination
     */
    public function createPaginationQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.user', 'u')
            ->leftJoin('b.referralFrom', 'rf');

        if (isset($filters['user'])) {
            $qb->andWhere('b.user = :user')
                ->setParameter('user', $filters['user']);
        }

        if (isset($filters['type'])) {
            if (is_array($filters['type'])) {
                $qb->andWhere('b.type IN (:type)')
                    ->setParameter('type', $filters['type']);
            } else {
                $qb->andWhere('b.type = :type')
                    ->setParameter('type', $filters['type']);
            }
        }

        if (isset($filters['status'])) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('u.username', ':search'),
                $qb->expr()->like('b.description', ':search')
            ))
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['batch_id'])) {
            $qb->andWhere('b.batchId = :batch_id')
                ->setParameter('batch_id', $filters['batch_id']);
        }

        if (isset($filters['date_from'])) {
            $qb->andWhere('b.bonusDate >= :date_from')
                ->setParameter('date_from', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $qb->andWhere('b.bonusDate <= :date_to')
                ->setParameter('date_to', $filters['date_to']);
        }

        if (isset($filters['amount_min'])) {
            $qb->andWhere('b.amount >= :amount_min')
                ->setParameter('amount_min', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $qb->andWhere('b.amount <= :amount_max')
                ->setParameter('amount_max', $filters['amount_max']);
        }

        $qb->orderBy('b.createdAt', 'DESC');

        return $qb;
    }

    /**
     * Get top bonus earners
     *
     * @return array
     */
    public function getTopEarners(int $limit = 10, string $type = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('u.id, u.username, COUNT(b.id) as bonus_count, SUM(b.amount) as total_earned')
            ->join('b.user', 'u')
            ->where('b.status = :status')
            ->setParameter('status', Bonus::STATUS_DISTRIBUTED)
            ->groupBy('u.id')
            ->orderBy('total_earned', 'DESC')
            ->setMaxResults($limit);

        if ($type !== null) {
            $qb->andWhere('b.type = :type')
                ->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Calculate total bonuses for period
     */
    public function getTotalBonusesForPeriod(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $result = $this->createQueryBuilder('b')
            ->select('SUM(b.amount)')
            ->where('b.bonusDate BETWEEN :from AND :to')
            ->andWhere('b.status = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', Bonus::STATUS_DISTRIBUTED)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Get bonus pool statistics
     */
    public function getPoolStatistics(\DateTimeInterface $date): array
    {
        $result = $this->createQueryBuilder('b')
            ->select(
                'SUM(b.amount) as distributed_amount',
                'SUM(b.depositBalance) as total_deposits',
                'COUNT(b.id) as recipient_count',
                'MAX(b.totalProfit) as total_profit',
                'MAX(b.distributionPool) as distribution_pool'
            )
            ->where('b.bonusDate = :date')
            ->andWhere('b.type = :type')
            ->setParameter('date', $date)
            ->setParameter('type', Bonus::TYPE_DAILY)
            ->getQuery()
            ->getSingleResult();

        return [
            'distributed_amount' => $result['distributed_amount'] ?? '0',
            'total_deposits' => $result['total_deposits'] ?? '0',
            'recipient_count' => $result['recipient_count'] ?? 0,
            'total_profit' => $result['total_profit'] ?? '0',
            'distribution_pool' => $result['distribution_pool'] ?? '0',
        ];
    }
}