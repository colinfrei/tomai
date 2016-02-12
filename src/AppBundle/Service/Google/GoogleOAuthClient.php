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
        $this->client = new \Google_Client();
        $this->client->setApplicationName($applicationName);
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);

        if ($logger) {
            $this->client->setLogger($logger);
        }
    }

    public function getClient(User $user)
    {
        $token = array(
            'access_token' => $user->getGoogleAccessToken(),
            'refresh_token' => $user->getGoogleRefreshToken(),
            'expires_in' => null
        );

        $this->client->setAccessToken(json_encode($token));
        $this->client->fetchAccessTokenWithRefreshToken();

        return $this->client;
    }
}
