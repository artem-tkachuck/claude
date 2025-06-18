<?php

namespace App\Repository;

use App\Entity\Deposit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Deposit>
 *
 * @method Deposit|null find($id, $lockMode = null, $lockVersion = null)
 * @method Deposit|null findOneBy(array $criteria, array $orderBy = null)
 * @method Deposit[]    findAll()
 * @method Deposit[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DepositRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deposit::class);
    }

    public function save(Deposit $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Deposit $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find deposit by transaction hash
     */
    public function findByTransactionHash(string $hash): ?Deposit
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.transactionHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find pending deposits for processing
     *
     * @return Deposit[]
     */
    public function findPendingDeposits(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('statuses', [Deposit::STATUS_PENDING, Deposit::STATUS_CONFIRMING])
            ->andWhere('d.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find deposits by user
     *
     * @return Deposit[]
     */
    public function findByUser(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get user's deposit statistics
     */
    public function getUserStatistics(User $user): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->setParameter('user', $user);

        $totalDeposits = (clone $qb)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $confirmedDeposits = (clone $qb)
            ->select('COUNT(d.id)')
            ->andWhere('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        $totalAmount = (clone $qb)
            ->select('SUM(d.amount)')
            ->andWhere('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_deposits' => $totalDeposits,
            'confirmed_deposits' => $confirmedDeposits,
            'total_amount' => $totalAmount ?? '0',
            'average_amount' => $confirmedDeposits > 0 ? bcdiv($totalAmount ?? '0', (string)$confirmedDeposits, 8) : '0',
        ];
    }

    /**
     * Get deposits for bonus processing
     *
     * @return Deposit[]
     */
    public function findUnprocessedForBonus(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->andWhere('d.bonusProcessed = false')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->orderBy('d.confirmedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get deposits for referral processing
     *
     * @return Deposit[]
     */
    public function findUnprocessedForReferral(): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.user', 'u')
            ->andWhere('d.status = :status')
            ->andWhere('d.referralProcessed = false')
            ->andWhere('u.referrer IS NOT NULL')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->orderBy('d.confirmedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get expired deposits
     *
     * @return Deposit[]
     */
    public function findExpiredDeposits(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt < :now')
            ->setParameter('status', Deposit::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Get deposit statistics by date range
     */
    public function getStatisticsByDateRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $totalCount = (clone $qb)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $confirmedCount = (clone $qb)
            ->select('COUNT(d.id)')
            ->andWhere('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        $totalAmount = (clone $qb)
            ->select('SUM(d.amount)')
            ->andWhere('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        $uniqueUsers = (clone $qb)
            ->select('COUNT(DISTINCT d.user)')
            ->andWhere('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_count' => $totalCount,
            'confirmed_count' => $confirmedCount,
            'total_amount' => $totalAmount ?? '0',
            'unique_users' => $uniqueUsers,
            'confirmation_rate' => $totalCount > 0 ? round(($confirmedCount / $totalCount) * 100, 2) : 0,
        ];
    }

    /**
     * Get daily deposit statistics
     */
    public function getDailyStatistics(int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('d')
            ->select(
                'DATE(d.createdAt) as date',
                'COUNT(d.id) as count',
                'SUM(CASE WHEN d.status = :confirmed THEN d.amount ELSE 0 END) as amount',
                'COUNT(DISTINCT d.user) as unique_users'
            )
            ->where('d.createdAt >= :from')
            ->setParameter('from', $from)
            ->setParameter('confirmed', Deposit::STATUS_CONFIRMED)
            ->groupBy('date')
            ->orderBy('date', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find deposits by address
     *
     * @return Deposit[]
     */
    public function findByAddress(string $address, string $type = 'to'): array
    {
        $field = $type === 'from' ? 'fromAddress' : 'toAddress';

        return $this->createQueryBuilder('d')
            ->andWhere("d.{$field} = :address")
            ->setParameter('address', $address)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total deposits amount
     */
    public function getTotalDepositsAmount(): string
    {
        $result = $this->createQueryBuilder('d')
            ->select('SUM(d.amount)')
            ->where('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Get largest deposits
     *
     * @return Deposit[]
     */
    public function getLargestDeposits(int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->orderBy('d.amount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find deposits for monitoring
     *
     * @return Deposit[]
     */
    public function findForMonitoring(int $confirmations = 10): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->andWhere('d.confirmations < :confirmations')
            ->setParameter('status', Deposit::STATUS_CONFIRMING)
            ->setParameter('confirmations', $confirmations)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Create query builder for pagination
     */
    public function createPaginationQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.user', 'u');

        if (isset($filters['user'])) {
            $qb->andWhere('d.user = :user')
                ->setParameter('user', $filters['user']);
        }

        if (isset($filters['status'])) {
            $qb->andWhere('d.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['network'])) {
            $qb->andWhere('d.network = :network')
                ->setParameter('network', $filters['network']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('d.transactionHash', ':search'),
                $qb->expr()->like('d.fromAddress', ':search'),
                $qb->expr()->like('d.toAddress', ':search'),
                $qb->expr()->like('u.username', ':search')
            ))
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['amount_min'])) {
            $qb->andWhere('d.amount >= :amount_min')
                ->setParameter('amount_min', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $qb->andWhere('d.amount <= :amount_max')
                ->setParameter('amount_max', $filters['amount_max']);
        }

        if (isset($filters['date_from'])) {
            $qb->andWhere('d.createdAt >= :date_from')
                ->setParameter('date_from', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $qb->andWhere('d.createdAt <= :date_to')
                ->setParameter('date_to', $filters['date_to']);
        }

        $qb->orderBy('d.createdAt', 'DESC');

        return $qb;
    }

    /**
     * Mark deposits as expired
     */
    public function markExpiredDeposits(): int
    {
        return $this->createQueryBuilder('d')
            ->update()
            ->set('d.status', ':expired')
            ->where('d.status = :pending')
            ->andWhere('d.expiresAt < :now')
            ->setParameter('expired', Deposit::STATUS_EXPIRED)
            ->setParameter('pending', Deposit::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Check if user has first deposit
     */
    public function isFirstDeposit(User $user): bool
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.user = :user')
            ->andWhere('d.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', Deposit::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count == 0;
    }

    /**
     * Get deposits by block range
     *
     * @return Deposit[]
     */
    public function findByBlockRange(int $fromBlock, int $toBlock): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.blockNumber BETWEEN :from AND :to')
            ->setParameter('from', $fromBlock)
            ->setParameter('to', $toBlock)
            ->orderBy('d.blockNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}