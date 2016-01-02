<?php

namespace AppBundle\Service\Google;

use Psr\Log\LoggerInterface;

class GoogleServiceClient
{
    /**
     * @var \Google_Client
     */
    private $client;

    public function __construct($applicationName, $privateKeyPath, array $scopes, $subUser = null, LoggerInterface $logger = null)
    {
        if (!file_exists($privateKeyPath)) {
            throw new \RuntimeException(sprintf('Cannot find private key path: %s', $privateKeyPath));
        }
        $this->client = new \Google_Client();
        $this->client->setApplicationName($applicationName);
        $this->client->setScopes($scopes);

        if ($subUser) {
            $this->client->setSubject($subUser);
        }

        $this->client->setAuthConfig($privateKeyPath);

        if ($logger) {
            $this->client->setLogger($logger);
        }
    }

    public function getClient()
    {
        return $this->client;
    }
}
