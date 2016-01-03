<?php

namespace AppBundle\Controller;

use AppBundle\Entity\QueueMessage;
use AppBundle\Entity\User;
use AppBundle\Service\Google\GoogleOAuthClient;
use AppBundle\Service\Google\PubSub;
use AppBundle\Service\QueueProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route(service="controller.service")
 */
class ServiceController
{
    private $entityManager;
    private $logger;
    private $googleClient;
    private $queueProcessor;
    private $pubSubClient;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger, GoogleOAuthClient $googleClient, QueueProcessor $queueProcessor, PubSub $pubSubClient)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->googleClient = $googleClient;
        $this->queueProcessor = $queueProcessor;
        $this->pubSubClient = $pubSubClient;
    }

    private function getGoogleClient(User $user)
    {
        return $this->googleClient->getClient($user);
    }

    /**
     * @Route("/pull", name="pull-messages")
     */
    public function pullMessagesAction(Request $request)
    {
        $pullRequest = new \Google_Service_Pubsub_PullRequest();
        $pullRequest->setMaxMessages(2);

        $subscriptionUrl = 'projects/' . $this->getParameter('google_project_id') . '/subscriptions/' . $this->getParameter('google_pubsub_topicname');

        $pullResponse = $this->pubSubClient->projects_subscriptions->pull($subscriptionUrl, $pullRequest);
        /** @var \Google_Service_Pubsub_ReceivedMessage $receivedMessage */
        foreach($pullResponse->getReceivedMessages() as $receivedMessage) {
            $message = json_decode(base64_decode($receivedMessage->getMessage()->getData()), true);
            $user = $this->entityManager->getRepository('AppBundle:User')->findOneBy(array('email' => $message['emailAddress']));
            $this->logger->debug('Processing Google Pubsub Message', $message);

            $this->processHistory($user, $user->getGmailHistoryId());
            $user->setGmailHistoryId($message['historyId']);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $ackRequest = new \Google_Service_Pubsub_AcknowledgeRequest();
            $ackRequest->setAckIds($receivedMessage->getAckId());
            $this->pubSubClient->projects_subscriptions->acknowledge($subscriptionUrl, $ackRequest);
        }

        return new Response('', '204');
    }

    /**
     * @Route("/google-push", name="google-push")
     * @Method({"POST"})
     */
    public function googlePushAction(Request $request)
    {
        $messageData = json_decode($request->getContent());
        $message = json_decode(base64_decode($messageData->message->data), true);
        /** @var User $user */
        $user = $this->entityManager->getRepository('AppBundle:User')->findOneBy(array('email' => $message['emailAddress']));


        $this->logger->debug('Processing Google Pubsub Message', $message);

        $this->processHistory($user, $user->getGmailHistoryId());
        $user->setGmailHistoryId($message['historyId']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new Response('', 204);
    }

    /**
     * @Route("/process-queue", name="process-queue")
     * @Method({"GET"})
     */
    public function processQueueMessagesAction(Request $request)
    {
        $this->queueProcessor->process();

        return new Response('Successfully processed output');
    }



    private function processHistory(User $user, $historyId)
    {
        $gmail = new \Google_Service_Gmail($this->getGoogleClient($user));

        $history = $this->listHistory($gmail, $user->getEmail(), $historyId);
        /** @var \Google_Service_Gmail_History $historyPart */
        foreach ($history as $historyPart) {
            $this->logger->debug('Processing history part', array('content' => print_r($historyPart, true)));

            foreach ($historyPart->getLabelsAdded() as $historyMessage) {
                $queueMessage = new QueueMessage($historyMessage->getMessage()->id, $user->getEmail());
                $this->entityManager->getRepository('AppBundle:QueueMessage')->insertOnDuplicateKeyUpdate($queueMessage);
            }

            foreach ($historyPart->getMessagesAdded() as $historyMessage) {
                $queueMessage = new QueueMessage($historyMessage->getMessage()->getId(), $user->getEmail());

                $this->entityManager->getRepository('AppBundle:QueueMessage')->insertOnDuplicateKeyUpdate($queueMessage);
            }

            $this->entityManager->flush();
        }
    }

    private function listHistory(\Google_Service_Gmail $service, $userId, $startHistoryId) {
        //TODO: include filter by labelid?
        $opt_param = array('startHistoryId' => $startHistoryId);
        $pageToken = NULL;
        $histories = array();

        do {
            try {
                if ($pageToken) {
                    $opt_param['pageToken'] = $pageToken;
                }
                $historyResponse = $service->users_history->listUsersHistory($userId, $opt_param);
                if ($historyResponse->getHistory()) {
                    $histories = array_merge($histories, $historyResponse->getHistory());
                    $pageToken = $historyResponse->getNextPageToken();
                }
            } catch (\Exception $e) {
                print 'An error occurred: ' . $e->getMessage();
            }
        } while ($pageToken);


        return $histories;
    }

}
