<?php

namespace AppBundle\Service;

use AppBundle\Service\Google\PubSub;

class PubSubHelper
{
    private $pubSubClient;
    private $subscriptionUrl;

    public function __construct(PubSub $pubSubClient, $googleProjectId, $pubsubTopicname)
    {
        $this->pubSubClient = $pubSubClient;
        $this->subscriptionUrl = 'projects/' . $googleProjectId . '/subscriptions/' . $pubsubTopicname;
    }

    public function makePullRequest($maxMessages)
    {
        $pullRequest = new \Google_Service_Pubsub_PullRequest();
        $pullRequest->setMaxMessages($maxMessages);

        return $this->pubSubClient->projects_subscriptions->pull($this->subscriptionUrl, $pullRequest);
    }

    public function ackRequest($ackId)
    {
        $ackRequest = new \Google_Service_Pubsub_AcknowledgeRequest();
        $ackRequest->setAckIds($ackId);

        $this->pubSubClient->projects_subscriptions->acknowledge($this->subscriptionUrl, $ackRequest);
    }
}
