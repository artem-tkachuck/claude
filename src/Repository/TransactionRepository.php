<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 *
 * @method Transaction|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transaction|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transaction[]    findAll()
 * @method Transaction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function save(Transaction $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Transaction $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find transaction by reference ID
     */
    public function findByReferenceId(string $referenceId): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.referenceId = :referenceId')
            ->setParameter('referenceId', $referenceId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find transactions by user
     *
     * @return Transaction[]
     */
    public function findByUser(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find transactions by type
     *
     * @return Transaction[]
     */
    public function findByType(string $type, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.type = :type')
            ->setParameter('type', $type)
            ->orderBy('t.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get user transaction history with balance calculation
     *
     * @return Transaction[]
     */
    public function getUserTransactionHistory(User $user, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user);

        if (isset($filters['type'])) {
            $qb->andWhere('t.type = :type')
                ->setParameter('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $qb->andWhere('t.createdAt >= :date_from')
                ->setParameter('date_from', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $qb->andWhere('t.createdAt <= :date_to')
                ->setParameter('date_to', $filters['date_to']);
        }

        $qb->orderBy('t.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get balance by type for user
     */
    public function getBalanceByType(User $user, string $balanceType): string
    {
        // Get all completed transactions for this balance type
        $transactions = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.balanceType = :balanceType')
            ->andWhere('t.status = :status')
            ->setParameter('user', $user)
            ->setParameter('balanceType', $balanceType)
            ->setParameter('status', Transaction::STATUS_COMPLETED)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $balance = '0';
        foreach ($transactions as $transaction) {
            if ($transaction->isCredit()) {
                $balance = bcadd($balance, $transaction->getAmount(), 8);
            } else {
                $balance = bcsub($balance, $transaction->getAmount(), 8);
            }
        }

        return $balance;
    }

    /**
     * Get transaction statistics by date range
     */
    public function getStatisticsByDateRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.createdAt BETWEEN :from AND :to')
            ->andWhere('t.status = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', Transaction::STATUS_COMPLETED);

        $result = [];

        // Group by type
        $types = [
            Transaction::TYPE_DEPOSIT,
            Transaction::TYPE_WITHDRAWAL,
            Transaction::TYPE_BONUS,
            Transaction::TYPE_REFERRAL_BONUS,
            Transaction::TYPE_FEE
        ];

        foreach ($types as $type) {
            $typeQb = clone $qb;
            $stats = $typeQb
                ->select('COUNT(t.id) as count, SUM(t.amount) as total')
                ->andWhere('t.type = :type')
                ->setParameter('type', $type)
                ->getQuery()
                ->getSingleResult();

            $result[$type] = [
                'count' => $stats['count'] ?? 0,
                'total' => $stats['total'] ?? '0',
            ];
        }

        // Overall statistics
        $overall = $qb
            ->select('COUNT(t.id) as count, COUNT(DISTINCT t.user) as unique_users')
            ->getQuery()
            ->getSingleResult();

        $result['overall'] = [
            'count' => $overall['count'] ?? 0,
            'unique_users' => $overall['unique_users'] ?? 0,
        ];

        return $result;
    }

    /**
     * Get daily transaction statistics
     */
    public function getDailyStatistics(int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");

        $result = $this->createQueryBuilder('t')
            ->select(
                'DATE(t.createdAt) as date',
                't.type',
                'COUNT(t.id) as count',
                'SUM(t.amount) as amount'
            )
            ->where('t.createdAt >= :from')
            ->andWhere('t.status = :status')
            ->setParameter('from', $from)
            ->setParameter('status', Transaction::STATUS_COMPLETED)
            ->groupBy('date', 't.type')
            ->orderBy('date', 'DESC')
            ->getQuery()
            ->getResult();

        // Organize by date
        $organized = [];
        foreach ($result as $row) {
            $date = $row['date'];
            if (!isset($organized[$date])) {
                $organized[$date] = [
                    'date' => $date,
                    'types' => [],
                    'total_count' => 0,
                    'total_amount' => '0',
                ];
            }

            $organized[$date]['types'][$row['type']] = [
                'count' => $row['count'],
                'amount' => $row['amount'],
            ];

            $organized[$date]['total_count'] += $row['count'];
            $organized[$date]['total_amount'] = bcadd($organized[$date]['total_amount'], $row['amount'] ?? '0', 8);
        }

        return array_values($organized);
    }

    /**
     * Find pending transactions
     *
     * @return Transaction[]
     */
    public function findPendingTransactions(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.status = :status')
            ->setParameter('status', Transaction::STATUS_PENDING)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find transactions that can be reversed
     *
     * @return Transaction[]
     */
    public function findReversibleTransactions(User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.status = :status')
            ->andWhere('t.isReversed = false')
            ->andWhere('t.type IN (:types)')
            ->setParameter('status', Transaction::STATUS_COMPLETED)
            ->setParameter('types', [
                Transaction::TYPE_DEPOSIT,
                Transaction::TYPE_BONUS,
                Transaction::TYPE_REFERRAL_BONUS,
                Transaction::TYPE_ADJUSTMENT
            ]);

        if ($user !== null) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $user);
        }

        return $qb->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get fee statistics
     */
    public function getFeeStatistics(\DateTimeInterface $from = null, \DateTimeInterface $to = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('SUM(t.fee) as total_fees, COUNT(t.id) as count')
            ->where('t.fee > 0')
            ->andWhere('t.status = :status')
            ->setParameter('status', Transaction::STATUS_COMPLETED);

        if ($from !== null) {
            $qb->andWhere('t.createdAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('t.createdAt <= :to')
                ->setParameter('to', $to);
        }

        $result = $qb->getQuery()->getSingleResult();

        return [
            'total_fees' => $result['total_fees'] ?? '0',
            'transaction_count' => $result['count'] ?? 0,
            'average_fee' => $result['count'] > 0 ? bcdiv($result['total_fees'] ?? '0', (string)$result['count'], 8) : '0',
        ];
    }

    /**
     * Create query builder for pagination
     */
    public function createPaginationQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.user', 'u');

        if (isset($filters['user'])) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $filters['user']);
        }

        if (isset($filters['type'])) {
            if (is_array($filters['type'])) {
                $qb->andWhere('t.type IN (:type)')
                    ->setParameter('type', $filters['type']);
            } else {
                $qb->andWhere('t.type = :type')
                    ->setParameter('type', $filters['type']);
            }
        }

        if (isset($filters['status'])) {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (isset($filters['balance_type'])) {
            $qb->andWhere('t.balanceType = :balance_type')
                ->setParameter('balance_type', $filters['balance_type']);
        }

        if (isset($filters['search'])) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('t.referenceId', ':search'),
                $qb->expr()->like('t.description', ':search'),
                $qb->expr()->like('u.username', ':search')
            ))
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['amount_min'])) {
            $qb->andWhere('t.amount >= :amount_min')
                ->setParameter('amount_min', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $qb->andWhere('t.amount <= :amount_max')
                ->setParameter('amount_max', $filters['amount_max']);
        }

        if (isset($filters['date_from'])) {
            $qb->andWhere('t.createdAt >= :date_from')
                ->setParameter('date_from', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $qb->andWhere('t.createdAt <= :date_to')
                ->setParameter('date_to', $filters['date_to']);
        }

        $qb->orderBy('t.createdAt', 'DESC');

        return $qb;
    }

    /**
     * Get volume statistics
     */
    public function getVolumeStatistics(): array
    {
        $today = new \DateTimeImmutable('today');
        $yesterday = new \DateTimeImmutable('yesterday');
        $weekAgo = new \DateTimeImmutable('-7 days');
        $monthAgo = new \DateTimeImmutable('-30 days');

        return [
            'today' => $this->getVolumeForPeriod($today, new \DateTimeImmutable('tomorrow')),
            'yesterday' => $this->getVolumeForPeriod($yesterday, $today),
            'last_7_days' => $this->getVolumeForPeriod($weekAgo, new \DateTimeImmutable('tomorrow')),
            'last_30_days' => $this->getVolumeForPeriod($monthAgo, new \DateTimeImmutable('tomorrow')),
        ];
    }

    private function getVolumeForPeriod(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $result = $this->createQueryBuilder('t')
            ->select(
                'COUNT(t.id) as count',
                'SUM(CASE WHEN t.type = :deposit THEN t.amount ELSE 0 END) as deposits',
                'SUM(CASE WHEN t.type = :withdrawal THEN t.amount ELSE 0 END) as withdrawals',
                'SUM(CASE WHEN t.type = :bonus THEN t.amount ELSE 0 END) as bonuses'
            )
            ->where('t.createdAt >= :from')
            ->andWhere('t.createdAt < :to')
            ->andWhere('t.status = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', Transaction::STATUS_COMPLETED)
            ->setParameter('deposit', Transaction::TYPE_DEPOSIT)
            ->setParameter('withdrawal', Transaction::TYPE_WITHDRAWAL)
            ->setParameter('bonus', Transaction::TYPE_BONUS)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => $result['count'] ?? 0,
            'deposits' => $result['deposits'] ?? '0',
            'withdrawals' => $result['withdrawals'] ?? '0',
            'bonuses' => $result['bonuses'] ?? '0',
            'total' => bcadd(
                bcadd($result['deposits'] ?? '0', $result['bonuses'] ?? '0', 8),
                $result['withdrawals'] ?? '0',
                8
            ),
        ];
    }

    /**
     * Check for duplicate transactions
     */
    public function findDuplicates(User $user, string $type, string $amount, \DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.type = :type')
            ->andWhere('t.amount = :amount')
            ->andWhere('t.createdAt >= :since')
            ->andWhere('t.status != :failed')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('amount', $amount)
            ->setParameter('since', $since)
            ->setParameter('failed', Transaction::STATUS_FAILED)
            ->getQuery()
            ->getResult();
    }
}