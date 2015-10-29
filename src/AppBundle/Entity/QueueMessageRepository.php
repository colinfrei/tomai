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
        $database = $this->getEntityManager()->getConnection()->getDatabasePlatform()->getName();

        switch ($database) {
            case 'sqlite':
                $query = $this->getEntityManager()->getConnection()->prepare("
                    INSERT OR REPLACE INTO queue_message
                      (`message_id`, `google_email`, `timestamp`)
                    VALUES
                      ('" . $message->getMessageId() . "', '" . $message->getGoogleEmail() . "', '" . $message->getTimestamp() . "')
                    ");
            break;

            case 'mysql':
                $query = $this->getEntityManager()->getConnection()->prepare("
                    REPLACE INTO queue_message
                      (`message_id`, `google_email`, `timestamp`)
                    VALUES
                      ('" . $message->getMessageId() . "', '" . $message->getGoogleEmail() . "', '" . $message->getTimestamp() . "')
                    ");
            break;

            default:
                throw new \LogicException('Invalid Database platform: ' . $database);
        }

        $query->execute();
    }
}
