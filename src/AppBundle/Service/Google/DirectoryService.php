<?php

namespace AppBundle\Service\Google;

class DirectoryService extends \Google_Service_Directory
{
    public $client;

    public function __construct(GoogleServiceClient $client)
    {
        $this->client = $client;

        parent::__construct($client->getClient());
    }
}
