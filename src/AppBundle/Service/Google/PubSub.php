<?php

namespace AppBundle\Service\Google;

class PubSub extends \Google_Service_Pubsub
{
    public $client;

    public function __construct(GoogleServiceClient $client)
    {
        $this->client = $client;

        parent::__construct($client->getClient());
    }
}
