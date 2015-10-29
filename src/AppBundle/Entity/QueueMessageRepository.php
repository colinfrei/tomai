<?php

namespace AppBundle\Entity;

class QueueMessageRepository extends \Doctrine\ORM\EntityRepository
{
    public function findMessagesOlderThanX($fromTimestamp)
    {
        $qb = $this->createQueryBuilder('qm');
        $qb->where('qm.timestamp < :timestamp')
            ->orderBy('qm.timestamp', 'ASC')
            ->setMaxResults(100) // TODO: config? pass in?
            ->setParameter('timestamp', $fromTimestamp);

        $query = $qb->getQuery();

        return $query->getResult();
    }

    public function insertOnDuplicateKeyUpdate(QueueMessage $message)
    {
        switch ($this->getEntityManager()->getConnection()->getDatabasePlatform()) {
            case 'SqlitePlatform':
                $query = $this->getEntityManager()->getConnection()->prepare("
                    INSERT OR REPLACE INTO queue_message
                      (`message_id`, `google_email`, `timestamp`)
                    VALUES
                      ('" . $message->getMessageId() . "', '" . $message->getGoogleEmail() . "', '" . $message->getTimestamp() . "')
                    ");
            break;

            case 'MySqlPlatform':
            case 'MySql57Platform':
                $query = $this->getEntityManager()->getConnection()->prepare("
                    REPLACE INTO queue_message
                      (`message_id`, `google_email`, `timestamp`)
                    VALUES
                      ('" . $message->getMessageId() . "', '" . $message->getGoogleEmail() . "', '" . $message->getTimestamp() . "')
                    ");
            break;

            default:
                throw new \LogicException('Invalid Database platform: ' . $this->getEntityManager()->getConnection()->getDatabasePlatform());
        }

        $query->execute();
    }
}
