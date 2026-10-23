<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Re-hachage automatique quand l'algorithme « auto » évolue. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * Techniciens actifs, proposés à l'assignation.
     *
     * @return list<User>
     */
    public function findActiveTechnicians(): array
    {
        /** @var list<User> $result */
        $result = $this->createQueryBuilder('u')
            ->andWhere('u.role = :role')
            ->andWhere('u.active = :active')
            ->setParameter('role', UserRole::Technicien->value)
            ->setParameter('active', true)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findActiveTechnicianByEmail(string $email): ?User
    {
        /** @var User|null $user */
        $user = $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->andWhere('u.role = :role')
            ->andWhere('u.active = :active')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('role', UserRole::Technicien->value)
            ->setParameter('active', true)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    /**
     * @return list<User>
     */
    public function findAllOrdered(): array
    {
        /** @var list<User> $result */
        $result = $this->createQueryBuilder('u')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
