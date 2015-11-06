<?php

namespace AppBundle\Service\Google;

class GoogleServiceClient
{
    /**
     * @var \Google_Client
     */
    protected $client;

    /**
     * Constructor
     *
     * @param string      $clientId           Id of client
     * @param string      $serviceAccountName Account name (service type)
     * @param string      $applicationName    Application Name
     * @param string      $privateKeyPath     Path to private key file
     * @param array       $scopes             Authentication scopes
     * @param string|null $sessionCacheDir    Session cache directory, null for default
     *
     * @throws \RuntimeException      If private key file does not exist or is not readable.
     * @throws \Google_Auth_Exception If authentication with Google fails.
     */
    public function __construct($clientId, $serviceAccountName, $applicationName, $privateKeyPath, array $scopes, $subUser = null, $sessionCacheDir = null)
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
    }

    public function getClient()
    {
        return $this->client;
    }
}
