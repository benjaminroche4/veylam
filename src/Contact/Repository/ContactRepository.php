<?php

declare(strict_types=1);

namespace App\Contact\Repository;

use App\Contact\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
final class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
