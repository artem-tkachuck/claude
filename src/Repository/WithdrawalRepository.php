<?php

namespace App\Repository;

use App\Entity\Withdrawal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Withdrawal>
 *
 * @method Withdrawal|null find($id, $lockMode = null, $lockVersion = null)
 * @method Withdrawal|null findOneBy(array $criteria, array $orderBy = null)
 * @method Withdrawal[]    findAll()
 * @method Withdrawal[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WithdrawalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Withdrawal::class);
    }

    public function save(Withdrawal $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Withdrawal $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find withdrawal by transaction hash
     */
    public function findByTransactionHash(string $hash): ?Withdrawal
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.transactionHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find withdrawal by reference ID
     */
    public function findByReferenceId(string $referenceId): ?Withdrawal
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.referenceId = :referenceId')
            ->setParameter('referenceId', $referenceId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find pending withdrawals
     *
     * @return Withdrawal[]
     */
    public function findPendingWithdrawals(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status IN (:statuses)')
            ->setParameter('statuses', [
                Withdrawal::STATUS_PENDING,
                Withdrawal::STATUS_AWAITING_APPROVAL
            ])
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find withdrawals awaiting approval
     *
     * @return Withdrawal[]
     */
    public function findAwaitingApproval(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_AWAITING_APPROVAL)
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find approved withdrawals ready for processing
     *
     * @return Withdrawal[]
     */
    public function findApprovedForProcessing(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_APPROVED)
            ->orderBy('w.approvedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get user's daily withdrawal total
     */
    public function getUserDailyTotal(User $user, \DateTimeInterface $date = null): string
    {
        if ($date === null) {
            $date = new \DateTime();
        }

        $startOfDay = clone $date;
        $startOfDay->setTime(0, 0, 0);

        $endOfDay = clone $date;
        $endOfDay->setTime(23, 59, 59);

        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.amount)')
            ->where('w.user = :user')
            ->andWhere('w.createdAt BETWEEN :start AND :end')
            ->andWhere('w.status NOT IN (:excludedStatuses)')
            ->setParameter('user', $user)
            ->setParameter('start', $startOfDay)
            ->setParameter('end', $endOfDay)
            ->setParameter('excludedStatuses', [
                Withdrawal::STATUS_REJECTED,
                Withdrawal::STATUS_FAILED,
                Withdrawal::STATUS_CANCELLED
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Find withdrawals by user
     *
     * @return Withdrawal[]
     */
    public function findByUser(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get user's withdrawal statistics
     */
    public function getUserStatistics(User $user): array
    {
        $qb = $this->createQueryBuilder('w')
            ->where('w.user = :user')
            ->setParameter('user', $user);

        $totalWithdrawals = (clone $qb)
            ->select('COUNT(w.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $completedWithdrawals = (clone $qb)
            ->select('COUNT(w.id)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $totalAmount = (clone $qb)
            ->select('SUM(w.amount)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $totalFees = (clone $qb)
            ->select('SUM(w.fee)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $pendingAmount = (clone $qb)
            ->select('SUM(w.amount)')
            ->andWhere('w.status IN (:statuses)')
            ->setParameter('statuses', [
                Withdrawal::STATUS_PENDING,
                Withdrawal::STATUS_AWAITING_APPROVAL,
                Withdrawal::STATUS_APPROVED,
                Withdrawal::STATUS_PROCESSING
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_withdrawals' => $totalWithdrawals,
            'completed_withdrawals' => $completedWithdrawals,
            'total_amount' => $totalAmount ?? '0',
            'total_fees' => $totalFees ?? '0',
            'pending_amount' => $pendingAmount ?? '0',
            'average_amount' => $completedWithdrawals > 0 ? bcdiv($totalAmount ?? '0', (string)$completedWithdrawals, 8) : '0',
        ];
    }

    /**
     * Get withdrawal statistics by date range
     */
    public function getStatisticsByDateRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $qb = $this->createQueryBuilder('w')
            ->where('w.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $totalCount = (clone $qb)
            ->select('COUNT(w.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $completedCount = (clone $qb)
            ->select('COUNT(w.id)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $totalAmount = (clone $qb)
            ->select('SUM(w.amount)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $totalFees = (clone $qb)
            ->select('SUM(w.fee)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        $uniqueUsers = (clone $qb)
            ->select('COUNT(DISTINCT w.user)')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_count' => $totalCount,
            'completed_count' => $completedCount,
            'total_amount' => $totalAmount ?? '0',
            'total_fees' => $totalFees ?? '0',
            'unique_users' => $uniqueUsers,
            'completion_rate' => $totalCount > 0 ? round(($completedCount / $totalCount) * 100, 2) : 0,
        ];
    }

    /**
     * Get daily withdrawal statistics
     */
    public function getDailyStatistics(int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('w')
            ->select(
                'DATE(w.createdAt) as date',
                'COUNT(w.id) as count',
                'SUM(CASE WHEN w.status = :completed THEN w.amount ELSE 0 END) as amount',
                'SUM(CASE WHEN w.status = :completed THEN w.fee ELSE 0 END) as fees',
                'COUNT(DISTINCT w.user) as unique_users'
            )
            ->where('w.createdAt >= :from')
            ->setParameter('from', $from)
            ->setParameter('completed', Withdrawal::STATUS_COMPLETED)
            ->groupBy('date')
            ->orderBy('date', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find withdrawals by address
     *
     * @return Withdrawal[]
     */
    public function findByAddress(string $address): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.toAddress = :address')
            ->setParameter('address', $address)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total withdrawals amount
     */
    public function getTotalWithdrawalsAmount(): string
    {
        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.amount)')
            ->where('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Get largest withdrawals
     *
     * @return Withdrawal[]
     */
    public function getLargestWithdrawals(int $limit = 10): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_COMPLETED)
            ->orderBy('w.amount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find withdrawals requiring 2FA verification
     *
     * @return Withdrawal[]
     */
    public function findRequiring2FA(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.requiresTwoFactor = true')
            ->andWhere('w.twoFactorVerified = false')
            ->andWhere('w.status = :status')
            ->setParameter('status', Withdrawal::STATUS_PENDING)
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find withdrawals by status
     *
     * @return Withdrawal[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :status')
            ->setParameter('status', $status)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Create query builder for pagination
     */
    public function createPaginationQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('w')
            ->leftJoin('w.user', 'u');

        if (isset($filters['user'])) {
            $qb->andWhere('w.user = :user')
                ->setParameter('user', $filters['user']);
        }

        if (isset($filters['status'])) {
            if (is_array($filters['status'])) {
                $qb->andWhere('w.status IN (:status)')
                    ->setParameter('status', $filters['status']);
            } else {
                $qb->andWhere('w.status = :status')
                    ->setParameter('status', $filters['status']);
            }
        }

        if (isset($filters['source'])) {
            $qb->andWhere('w.source = :source')
                ->setParameter('source', $filters['source']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('w.transactionHash', ':search'),
                $qb->expr()->like('w.toAddress', ':search'),
                $qb->expr()->like('w.referenceId', ':search'),
                $qb->expr()->like('u.username', ':search')
            ))
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['amount_min'])) {
            $qb->andWhere('w.amount >= :amount_min')
                ->setParameter('amount_min', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $qb->andWhere('w.amount <= :amount_max')
                ->setParameter('amount_max', $filters['amount_max']);
        }

        if (isset($filters['date_from'])) {
            $qb->andWhere('w.createdAt >= :date_from')
                ->setParameter('date_from', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $qb->andWhere('w.createdAt <= :date_to')
                ->setParameter('date_to', $filters['date_to']);
        }

        if (isset($filters['needs_approval']) && $filters['needs_approval']) {
            $qb->andWhere('w.status = :awaiting')
                ->setParameter('awaiting', Withdrawal::STATUS_AWAITING_APPROVAL);
        }

        $qb->orderBy('w.createdAt', 'DESC');

        return $qb;
    }

    /**
     * Get withdrawals above auto-approve limit
     *
     * @return Withdrawal[]
     */
    public function findAboveAutoApproveLimit(float $limit): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.amount > :limit')
            ->andWhere('w.status = :status')
            ->setParameter('limit', $limit)
            ->setParameter('status', Withdrawal::STATUS_PENDING)
            ->orderBy('w.amount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if address is frequently used
     */
    public function getAddressUsageCount(string $address, User $excludeUser = null): int
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(DISTINCT w.user)')
            ->where('w.toAddress = :address')
            ->setParameter('address', $address);

        if ($excludeUser !== null) {
            $qb->andWhere('w.user != :user')
                ->setParameter('user', $excludeUser);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get processing statistics
     */
    public function getProcessingStatistics(): array
    {
        $pending = $this->createQueryBuilder('w')
            ->select('COUNT(w.id) as count, SUM(w.amount) as amount')
            ->where('w.status IN (:statuses)')
            ->setParameter('statuses', [
                Withdrawal::STATUS_PENDING,
                Withdrawal::STATUS_AWAITING_APPROVAL
            ])
            ->getQuery()
            ->getSingleResult();

        $processing = $this->createQueryBuilder('w')
            ->select('COUNT(w.id) as count, SUM(w.amount) as amount')
            ->where('w.status IN (:statuses)')
            ->setParameter('statuses', [
                Withdrawal::STATUS_APPROVED,
                Withdrawal::STATUS_PROCESSING
            ])
            ->getQuery()
            ->getSingleResult();

        return [
            'pending_count' => $pending['count'] ?? 0,
            'pending_amount' => $pending['amount'] ?? '0',
            'processing_count' => $processing['count'] ?? 0,
            'processing_amount' => $processing['amount'] ?? '0',
        ];
    }
}