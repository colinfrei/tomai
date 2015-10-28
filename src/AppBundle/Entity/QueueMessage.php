<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="queue_message")
 * @ORM\Entity(repositoryClass="AppBundle\Entity\QueueMessageRepository")
 */
class QueueMessage
{
    /**
     * @ORM\Id
     * @ORM\Column(name="message_id", type="string", length=255)
     */
    protected $message_id;

    /**
     * @ORM\Id
     * @ORM\Column(name="google_email", type="string", length=255)
     */
    protected $google_email;

    /**
     * @ORM\Column(type="integer", name="timestamp")
     */
    protected $timestamp;

    public function __construct($message_id, $google_email)
    {
        $this->message_id = $message_id;
        $this->google_email = $google_email;

        $now = microtime(true) * 1000;
        $this->timestamp = (int)$now;
    }

    /**
     * @return mixed
     */
    public function getMessageId()
    {
        return $this->message_id;
    }

    /**
     * @return mixed
     */
    public function getGoogleEmail()
    {
        return $this->google_email;
    }

    /**
     * @return mixed
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }
}
