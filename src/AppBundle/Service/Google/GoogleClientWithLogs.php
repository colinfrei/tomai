<?php

namespace AppBundle\Service\Google;

use GuzzleHttp\Subscriber\Log\LogSubscriber;

class GoogleClientWithLogs extends \Google_Client
{
    protected function createDefaultHttpClient()
    {
        $client = parent::createDefaultHttpClient();

        $client->getEmitter()->attach(new LogSubscriber($this->getLogger()));

        return $client;
    }
}
