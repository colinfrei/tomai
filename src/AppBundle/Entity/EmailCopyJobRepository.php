<?php

namespace AppBundle\Entity;

class EmailCopyJobRepository extends \Doctrine\ORM\EntityRepository
{
    public function findMessagesWithUserForList()
    {
        $qb = $this->createQueryBuilder('c');
        $qb->leftJoin('c.user', 'u');

        $query = $qb->getQuery();

        return $query->getResult();
    }
}
