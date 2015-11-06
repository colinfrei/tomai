<?php

namespace AppBundle\Service\Google;

use Psr\Log\LoggerInterface;

class GoogleServiceClient
{
    /**
     * @var \Google_Client
     */
    private $client;

    public function __construct($clientId, $serviceAccountName, $applicationName, $privateKeyPath, array $scopes, $subUser = null, $sessionCacheDir = null, LoggerInterface $symfonyLogger = null)
    {
        if (!file_exists($privateKeyPath)) {
            throw new \RuntimeException(sprintf('Cannot find private key path: %s', $privateKeyPath));
        }
        $privateKey = file_get_contents($privateKeyPath);
        if (false === $privateKey) {
            throw new \RuntimeException(sprintf('Cannot read private key path: %s', $privateKeyPath));
        }

        $this->client = new \Google_Client();
        $this->client->setApplicationName($applicationName);
        if (null !== $sessionCacheDir) {
            $this->client->setClassConfig('Google_Cache_File', array('directory' => $sessionCacheDir));
        }

        $credentials = new \Google_Auth_AssertionCredentials(
            $serviceAccountName,
            $scopes,
            $privateKey
        );

        if ($subUser) {
            $credentials->sub = $subUser;
        }

        $this->client->setAssertionCredentials($credentials);
        if ($this->client->getAuth()->isAccessTokenExpired()) {
            $this->client->getAuth()->refreshTokenWithAssertion($credentials);
        }

        if ($symfonyLogger) {
            $googleLogger = new \Google_Logger_Psr($this->client, $symfonyLogger);
            $this->client->setLogger($googleLogger);
        }
    }

    public function getClient()
    {
        return $this->client;
    }
}
