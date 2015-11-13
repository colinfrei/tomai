<?php

namespace AppBundle\Entity;

class UserRepository extends \Doctrine\ORM\EntityRepository
{
    public function findUsersWithCopiesByEmail(array $emails)
    {
        $qb = $this->createQueryBuilder('u');
        $qb->where($qb->expr()->in('u.email', $emails));

        $query = $qb->getQuery();

        return $query->getResult();
    }
}
