<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->save($user, true);
    }

    /**
     * Find user by Telegram ID
     */
    public function findByTelegramId(int $telegramId): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.telegramId = :telegramId')
            ->setParameter('telegramId', $telegramId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find user by referral code
     */
    public function findByReferralCode(string $referralCode): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.referralCode = :code')
            ->setParameter('code', $referralCode)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find users by role
     *
     * @return User[]
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('JSON_CONTAINS(u.roles, :role) = 1')
            ->setParameter('role', json_encode($role))
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all admins
     *
     * @return User[]
     */
    public function findAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('JSON_CONTAINS(u.roles, :role1) = 1 OR JSON_CONTAINS(u.roles, :role2) = 1')
            ->setParameter('role1', json_encode('ROLE_ADMIN'))
            ->setParameter('role2', json_encode('ROLE_SUPER_ADMIN'))
            ->getQuery()
            ->getResult();
    }

    /**
     * Find users with deposits
     *
     * @return User[]
     */
    public function findUsersWithDeposits(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.depositBalance > 0')
            ->andWhere('u.isActive = true')
            ->orderBy('u.depositBalance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total deposits across all users
     */
    public function getTotalDeposits(): string
    {
        $result = $this->createQueryBuilder('u')
            ->select('SUM(u.depositBalance) as total')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Get total bonuses distributed
     */
    public function getTotalBonusesDistributed(): string
    {
        $result = $this->createQueryBuilder('u')
            ->select('SUM(u.totalBonusEarned) as total')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Get total withdrawals
     */
    public function getTotalWithdrawals(): string
    {
        $result = $this->createQueryBuilder('u')
            ->select('SUM(u.totalWithdrawn) as total')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? '0';
    }

    /**
     * Get users statistics
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('u');

        return [
            'total_users' => $qb->select('COUNT(u.id)')
                ->getQuery()
                ->getSingleScalarResult(),

            'active_users' => $qb->select('COUNT(u.id)')
                ->where('u.isActive = true')
                ->getQuery()
                ->getSingleScalarResult(),

            'users_with_deposits' => $qb->select('COUNT(u.id)')
                ->where('u.depositBalance > 0')
                ->getQuery()
                ->getSingleScalarResult(),

            'users_with_2fa' => $qb->select('COUNT(u.id)')
                ->where('u.twoFactorEnabled = true')
                ->getQuery()
                ->getSingleScalarResult(),

            'total_deposits' => $this->getTotalDeposits(),
            'total_bonuses' => $this->getTotalBonusesDistributed(),
            'total_withdrawals' => $this->getTotalWithdrawals(),
        ];
    }

    /**
     * Find inactive users (no activity for X days)
     *
     * @return User[]
     */
    public function findInactiveUsers(int $days = 30): array
    {
        $date = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('u')
            ->andWhere('u.lastActivityAt < :date')
            ->setParameter('date', $date)
            ->orderBy('u.lastActivityAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search users
     *
     * @return User[]
     */
    public function search(string $query): array
    {
        $qb = $this->createQueryBuilder('u');

        return $qb
            ->where($qb->expr()->orX(
                $qb->expr()->like('u.username', ':query'),
                $qb->expr()->like('u.email', ':query'),
                $qb->expr()->eq('u.telegramId', ':telegramId'),
                $qb->expr()->like('u.walletAddress', ':query')
            ))
            ->setParameter('query', '%' . $query . '%')
            ->setParameter('telegramId', is_numeric($query) ? $query : 0)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get referral tree for user
     *
     * @return User[]
     */
    public function getReferralTree(User $user, int $maxLevel = 2): array
    {
        $referrals = [];
        $this->loadReferralsRecursive($user, $referrals, 1, $maxLevel);
        return $referrals;
    }

    private function loadReferralsRecursive(User $user, array &$referrals, int $currentLevel, int $maxLevel): void
    {
        if ($currentLevel > $maxLevel) {
            return;
        }

        $directReferrals = $this->createQueryBuilder('u')
            ->andWhere('u.referrer = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach ($directReferrals as $referral) {
            $referrals[] = [
                'user' => $referral,
                'level' => $currentLevel,
            ];

            $this->loadReferralsRecursive($referral, $referrals, $currentLevel + 1, $maxLevel);
        }
    }

    /**
     * Get top depositors
     *
     * @return User[]
     */
    public function getTopDepositors(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.depositBalance > 0')
            ->orderBy('u.depositBalance', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get top earners (by total earnings)
     *
     * @return User[]
     */
    public function getTopEarners(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->select('u', '(u.totalBonusEarned + u.totalReferralEarned) as HIDDEN total_earned')
            ->having('total_earned > 0')
            ->orderBy('total_earned', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get users by country
     *
     * @return User[]
     */
    public function findByCountry(string $countryCode): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.countryCode = :country')
            ->setParameter('country', $countryCode)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get locked users
     *
     * @return User[]
     */
    public function findLockedUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.lockedUntil > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('u.lockedUntil', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Create query builder for pagination
     */
    public function createPaginationQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');

        if (isset($filters['search'])) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('u.username', ':search'),
                $qb->expr()->like('u.email', ':search')
            ))
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (isset($filters['role'])) {
            $qb->andWhere('JSON_CONTAINS(u.roles, :role) = 1')
                ->setParameter('role', json_encode($filters['role']));
        }

        if (isset($filters['active'])) {
            $qb->andWhere('u.isActive = :active')
                ->setParameter('active', $filters['active']);
        }

        if (isset($filters['has_deposit'])) {
            if ($filters['has_deposit']) {
                $qb->andWhere('u.depositBalance > 0');
            } else {
                $qb->andWhere('u.depositBalance = 0');
            }
        }

        if (isset($filters['country'])) {
            $qb->andWhere('u.countryCode = :country')
                ->setParameter('country', $filters['country']);
        }

        if (isset($filters['date_from'])) {
            $qb->andWhere('u.createdAt >= :date_from')
                ->setParameter('date_from', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $qb->andWhere('u.createdAt <= :date_to')
                ->setParameter('date_to', $filters['date_to']);
        }

        $qb->orderBy('u.createdAt', 'DESC');

        return $qb;
    }

    /**
     * Bulk update users
     */
    public function bulkUpdate(array $userIds, array $data): int
    {
        $qb = $this->createQueryBuilder('u')
            ->update()
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $userIds);

        foreach ($data as $field => $value) {
            $qb->set("u.$field", ":$field")
                ->setParameter($field, $value);
        }

        return $qb->getQuery()->execute();
    }

    /**
     * Get users for bonus distribution
     *
     * @return User[]
     */
    public function getUsersForBonusDistribution(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.depositBalance > 0')
            ->andWhere('u.isActive = true')
            ->andWhere('u.depositUnlockAt IS NOT NULL')
            ->orderBy('u.depositBalance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Update user balances atomically
     */
    public function updateBalance(User $user, string $balanceType, string $amount, string $operation = 'add'): bool
    {
        $field = match ($balanceType) {
            'deposit' => 'depositBalance',
            'bonus' => 'bonusBalance',
            'referral' => 'referralBalance',
            default => throw new \InvalidArgumentException('Invalid balance type'),
        };

        $qb = $this->createQueryBuilder('u')
            ->update()
            ->where('u.id = :userId')
            ->setParameter('userId', $user->getId());

        if ($operation === 'add') {
            $qb->set("u.$field", "u.$field + :amount");
        } else {
            $qb->set("u.$field", "u.$field - :amount")
                ->andWhere("u.$field >= :amount");
        }

        $qb->setParameter('amount', $amount);

        $result = $qb->getQuery()->execute();

        if ($result > 0) {
            $this->getEntityManager()->refresh($user);
            return true;
        }

        return false;
    }
}