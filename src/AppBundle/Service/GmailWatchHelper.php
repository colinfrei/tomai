<?php

namespace AppBundle\Service;

use AppBundle\Entity\EmailCopyJob;
use AppBundle\Service\Google\GoogleOAuthClient;
use Doctrine\ORM\EntityManagerInterface;

class GmailWatchHelper
{
    private $googleProjectId;
    private $pubsubTopicname;
    private $googleOAuthClient;
    private $entityManager;

    public function __construct($googleProjectId, $pubsubTopicname, GoogleOAuthClient $googleOAuthClient, EntityManagerInterface $entityManager)
    {
        $this->googleProjectId = $googleProjectId;
        $this->pubsubTopicname = $pubsubTopicname;
        $this->googleOAuthClient = $googleOAuthClient;
        $this->entityManager = $entityManager;
    }

    public function addGmailWatch(EmailCopyJob $copy)
    {
        $topicName = 'projects/' . $this->googleProjectId . '/topics/' . $this->pubsubTopicname;
        $copyUser = $copy->getUser();

        $gmail = new \Google_Service_Gmail($this->googleOAuthClient->getClient($copyUser));
        $watchRequest = new \Google_Service_Gmail_WatchRequest();
        $watchRequest->setTopicName($topicName);

        // Not setting labelIds on the watch request since we're handling that on our side
        $watchResponse = $gmail->users->watch($copyUser->getGoogleId(), $watchRequest);

        if (!$copyUser->getGmailHistoryId()) {
            $copyUser->setGmailHistoryId($watchResponse->getHistoryId());
            $this->entityManager->persist($copyUser);
        }
        
        $copy->updateLastWatchRenewal();
        $this->entityManager->persist($copy);
        $this->entityManager->flush();
    }
}
