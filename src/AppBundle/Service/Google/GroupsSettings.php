<?php

namespace AppBundle\Service\Google;

class GroupsSettings extends \Google_Service_Groupssettings
{
    public $client;

    public function __construct(GoogleServiceClient $client)
    {
        $this->client = $client;

        parent::__construct($client->getClient());
    }
}
