<?php

namespace AppBundle\Service\Google;

use AppBundle\Entity\User;
use Psr\Log\LoggerInterface;

class GoogleOAuthClient
{
    /**
     * @var \Google_Client
     */
    private $client;

    public function __construct($applicationName, $clientId, $clientSecret, LoggerInterface $logger = null)
    {
        $this->client = new GoogleClientWithLogs();
        $this->client->setApplicationName($applicationName);
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);

        // disable the cache, to see if it's the reason stupid things happen
        $this->client->setCache(new \Google_Cache_Null());

        if ($logger) {
            $this->client->setLogger($logger);
        }
    }

    public function getClient(User $user)
    {
        $newToken = $this->client->fetchAccessTokenWithRefreshToken($user->getGoogleRefreshToken());

        return $this->client;
    }
}
