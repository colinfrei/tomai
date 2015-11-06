<?php

namespace AppBundle\Service\Google;


class GroupsMigration extends \Google_Service_GroupsMigration
{
    public $client;

    public function __construct(GoogleServiceClient $client)
    {
        $this->client = $client;
        parent::__construct($client->getClient());
    }
}
