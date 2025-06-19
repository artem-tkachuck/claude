<?php

namespace App\Repository;

use App\Entity\EventLog;
use App\Entity\User;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventLog>
 *
 * @method EventLog|null find($id, $lockMode = null, $lockVersion = null)
 * @method EventLog|null findOneBy(array $criteria, array $orderBy = null)
 * @method EventLog[]    findAll()
 * @method EventLog[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EventLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventLog::class);
    }

    /**
     * Save event log entry
     */
    public function save(EventLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove event log entry
     */
    public function remove(EventLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find events by type
     *
     * @return EventLog[]
     */
    public function findByEventType(string $eventType, int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.eventType = :type')
            ->setParameter('type', $eventType)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find events by user
     *
     * @return EventLog[]
     */
    public function findByUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical events
     *
     * @return EventLog[]
     */
    public function findCriticalEvents(DateTime $since, int $limit = 100): array
    {
        $criticalTypes = [
            'security.breach_attempt',
            'security.multiple_failed_logins',
            'fraud.suspicious_activity',
            'fraud.duplicate_transaction',
            'system.critical_error',
            'admin.unauthorized_access'
        ];

        return $this->createQueryBuilder('e')
            ->andWhere('e.eventType IN (:types)')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('types', $criticalTypes)
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find events by date range
     *
     * @return EventLog[]
     */
    public function findByDateRange(DateTime $from, DateTime $to, ?string $eventType = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.createdAt', 'DESC');

        if ($eventType !== null) {
            $qb->andWhere('e.eventType = :type')
                ->setParameter('type', $eventType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get event statistics
     *
     * @return array<string, mixed>
     */
    public function getEventStatistics(DateTime $since): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                event_type,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM event_log
            WHERE created_at >= :since
            GROUP BY event_type, DATE(created_at)
            ORDER BY date DESC, count DESC
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['since' => $since->format('Y-m-d H:i:s')]);

        return $result->fetchAllAssociative();
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs(int $daysToKeep = 90): int
    {
        $date = new DateTime();
        $date->modify("-{$daysToKeep} days");

        $qb = $this->createQueryBuilder('e')
            ->delete()
            ->where('e.createdAt < :date')
            ->setParameter('date', $date);

        return $qb->getQuery()->execute();
    }

    /**
     * Find suspicious activities
     *
     * @return EventLog[]
     */
    public function findSuspiciousActivities(DateTime $since): array
    {
        $suspiciousTypes = [
            'auth.failed_login',
            'auth.invalid_token',
            'transaction.invalid_signature',
            'withdrawal.suspicious_amount',
            'api.rate_limit_exceeded'
        ];

        return $this->createQueryBuilder('e')
            ->select('e, u')
            ->leftJoin('e.user', 'u')
            ->andWhere('e.eventType IN (:types)')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('types', $suspiciousTypes)
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}