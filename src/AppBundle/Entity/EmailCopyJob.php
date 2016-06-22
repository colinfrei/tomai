<?php
// src/AppBundle/Entity/User.php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="copyemailjob")
 * @ORM\Entity(repositoryClass="AppBundle\Entity\EmailCopyJobRepository")
 */
class EmailCopyJob
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="User", inversedBy="copies")
     * @ORM\JoinColumn(name="user", referencedColumnName="id")
     */
    protected $user;

    /**
     * @ORM\Column(name="user_id", type="integer")
     */
    protected $user_id;

    /**
     * @ORM\Column(name="name", type="string", length=255)
     */
    protected $name;

    /**
     * @ORM\Column(name="labels", type="array")
     */
    protected $labels = array();

    /**
     * @ORM\Column(name="ignored_labels", type="array")
     */
    protected $ignored_labels = array();

    /**
     * @ORM\Column(name="startDate", type="date")
     */
    protected $startDate;

    /**
     * @ORM\Column(name="group_email", type="string", length=255)
     */
    protected $group_email;

    /**
     * @ORM\Column(name="group_url", type="string", length=255)
     */
    protected $group_url;

    /**
     * @ORM\Column(name="last_watch_renewal", type="date")
     */
    protected $lastWatchRenewal;

    public function __construct()
    {
        $this->startDate = new \DateTime();
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param User $user
     */
    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function getUserId()
    {
        return $this->user_id;
    }

    /**
     * @param mixed $user_id
     */
    public function setUserId($user_id)
    {
        $this->user_id = $user_id;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }


    /**
     * @return array
     */
    public function getLabels()
    {
        return $this->labels;
    }

    /**
     * @param array $labels
     */
    public function setLabels(array $labels)
    {
        $this->labels = $labels;
    }

    /**
     * @return array
     */
    public function getIgnoredLabels()
    {
        return $this->ignored_labels;
    }

    /**
     * @param array $ignored_labels
     */
    public function setIgnoredLabels(array $ignored_labels)
    {
        $this->ignored_labels = $ignored_labels;
    }

    /**
     * @return mixed
     */
    public function getGroupEmail()
    {
        return $this->group_email;
    }

    /**
     * @param mixed $group_email
     */
    public function setGroupEmail($group_email)
    {
        $this->group_email = $group_email;
    }

    /**
     * @return string
     */
    public function getGroupUrl()
    {
        return $this->group_url;
    }

    /**
     * @param string $group_url
     */
    public function setGroupUrl($group_url)
    {
        $this->group_url = $group_url;
    }

    /**
     * @return mixed
     */
    public function getStartDate()
    {
        return $this->startDate;
    }

    /**
     * @return mixed
     */
    public function getLastWatchRenewal()
    {
        return $this->lastWatchRenewal;
    }

    public function updateLastWatchRenewal()
    {
        $this->lastWatchRenewal = new \DateTime();
    }
}
