<?php
// src/AppBundle/Entity/User.php

namespace AppBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user")
 * @ORM\Entity(repositoryClass="AppBundle\Entity\UserRepository")
 */
class User extends BaseUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(name="google_id", type="string", length=255, nullable=true)
     */
    protected $google_id;

    /**
     * @ORM\Column(name="google_access_token", type="string", length=255, nullable=true)
     */
    protected $google_access_token;

    /**
     * @ORM\Column(name="email", type="string", length=255, nullable=true)
     */
    protected $email;

    /**
     * @ORM\Column(name="username", type="string", length=255, nullable=true)
     */
    protected $username;

    /**
     * @ORM\Column(name="google_refresh_token", type="string", length=255, nullable=true)
     */
    protected $google_refresh_token;

    /**
     * @ORM\Column(name="google_token_expiry", type="datetime", length=255, nullable=true)
     */
    protected $google_token_expiry;

    /**
     * @ORM\Column(name="gmail_history_id", type="string", length=20, nullable=true)
     */
    protected $gmail_history_id;

    /**
     * @ORM\OneToMany(targetEntity="EmailCopyJob", mappedBy="user")
     */
    protected $copies;


    public function __construct()
    {
        parent::__construct();

        $this->copies = new ArrayCollection();
    }

    /**
     * @return mixed
     */
    public function getGoogleId()
    {
        return $this->google_id;
    }

    /**
     * @param mixed $google_id
     */
    public function setGoogleId($google_id)
    {
        $this->google_id = $google_id;
    }

    /**
     * @return mixed
     */
    public function getGoogleAccessToken()
    {
        return $this->google_access_token;
    }

    /**
     * @param mixed $google_access_token
     */
    public function setGoogleAccessToken($google_access_token)
    {
        $this->google_access_token = $google_access_token;
    }

    /**
     * @return mixed
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param mixed $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * @return mixed
     */
    public function getGoogleRefreshToken()
    {
        return $this->google_refresh_token;
    }

    /**
     * @param mixed $google_refresh_token
     */
    public function setGoogleRefreshToken($google_refresh_token)
    {
        $this->google_refresh_token = $google_refresh_token;
    }

    /**
     * @return mixed
     */
    public function getGoogleTokenExpiry()
    {
        return $this->google_token_expiry;
    }

    /**
     * @param mixed $google_token_expiry
     */
    public function setGoogleTokenExpiry($google_token_expiry)
    {
        $this->google_token_expiry = $google_token_expiry;
    }

    /**
     * @return mixed
     */
    public function getGmailHistoryId()
    {
        return $this->gmail_history_id;
    }

    /**
     * @param mixed $gmail_history_id
     */
    public function setGmailHistoryId($gmail_history_id)
    {
        $this->gmail_history_id = $gmail_history_id;
    }

    /**
     * @return EmailCopyJob[]
     */
    public function getCopies()
    {
        return $this->copies;
    }
}
