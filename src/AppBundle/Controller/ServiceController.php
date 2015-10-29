<?php

namespace AppBundle\Controller;

use AppBundle\Entity\EmailCopyJob;
use AppBundle\Entity\QueueMessage;
use AppBundle\Entity\User;
use AppBundle\Service\QueueProcessor;
use Doctrine\ORM\EntityManagerInterface;
use HappyR\Google\ApiBundle\Services\GoogleClient;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route(service="controller.service")
 */
class ServiceController
{
    private $entityManager;
    private $logger;
    private $realGoogleClient;
    private $googleClient;
    private $queueProcessor;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger, GoogleClient $googleClient, QueueProcessor $queueProcessor)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->realGoogleClient = $googleClient;
        $this->queueProcessor = $queueProcessor;
    }

    private function getGoogleClient(User $user = null)
    {
        if (!isset($this->googleClient)) {
            $this->googleClient = $this->realGoogleClient;

            if (!$user) {
                $user = $this->getUser();
            }

            $token = array(
                'access_token' => $user->getGoogleAccessToken(),
                'refresh_token' => $user->getGoogleRefreshToken()
            );

            $this->googleClient->setAccessToken(json_encode($token));
        }

        return $this->googleClient;
    }


    /**
     * @Route("/pull", name="pull-messages")
     */
    public function pullMessagesAction(Request $request)
    {
        $pubsub = new \Google_Service_Pubsub($this->getGoogleClient()->getGoogleClient());

        $pullRequest = new \Google_Service_Pubsub_PullRequest();
        $pullRequest->setMaxMessages(2);

        $subscriptionUrl = 'projects/email-copier/subscriptions/adfhaerg'; //TODO: config this

        $pullResponse = $pubsub->projects_subscriptions->pull($subscriptionUrl, $pullRequest);
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
            $pubsub->projects_subscriptions->acknowledge($subscriptionUrl, $ackRequest);
        }

        return $this->render('default/index.html.twig', array(
            'base_dir' => realpath($this->container->getParameter('kernel.root_dir') . '/..'),
        ));
    }

    /**
     * @Route("/google-push", name="google-push")
     * @Method({"POST"})
     */
    public function googlePushAction(Request $request)
    {
        //TODO: add delay somehow
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
        $gmail = new \Google_Service_Gmail($this->getGoogleClient($user)->getGoogleClient());

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

        /*

                foreach ($user->getCopies() as $copy) { //TODO: move this outside foreach loop and use all the users labels for history filter
                /** @var \Google_Service_Gmail_HistoryLabelAdded $historyMessage */
        /*foreach ($historyPart->getLabelsAdded() as $historyMessage)
        {
            $queueMessages[] = new QueueMessage($historyMessage->getMessage()->id, $user->getEmail());
            if (!$this->shouldMessageBeHandled($copy, $historyMessage->getLabelIds())) {
                $this->getLogger()->debug(
                    'Skipped message for copy because it didn\'t match any relevant labels or had an ignored label',
                    array(
                        'copy id' => $copy->getId(),
                        'message id' => $historyMessage->getMessage()->id,
                        'message label ids' => $historyMessage->getLabelIds(),
                        'copy label ids' => $copy->getLabels(),
                        'copy ignored label ids' => $copy->getIgnoredLabels()
                    )
                );

                continue;
            }

            $actualMessage = $gmail->users_messages->get(
                $user->getEmail(),
                $historyMessage->getMessage()->getId(),
                array('format' => 'raw')
            );

            $this->handleMessage($actualMessage, $copy);
        }

        /** @var \Google_Service_Gmail_HistoryMessageAdded $historyMessage */
        /*foreach ($historyPart->getMessagesAdded() as $historyMessage) {
            if (!$this->shouldMessageBeHandled($copy, $historyMessage->getMessage()->labelIds)) {
                $this->getLogger()->debug(
                    'Skipped message for copy because it didn\'t match any relevant labels or had an ignored label',
                    array(
                        'copy id' => $copy->getId(),
                        'message id' => $historyMessage->getMessage()->id,
                        'message label ids' => $historyMessage->labelIds,
                        'copy label ids' => $copy->getLabels(),
                        'copy ignored label ids' => $copy->getIgnoredLabels()
                    )
                );

                continue;
            }

            $actualMessage = $gmail->users_messages->get(
                $user->getEmail(),
                $historyMessage->getMessage()->getId(),
                array('format' => 'raw')
            );

            $this->handleMessage($actualMessage, $copy);
        }
    }
}
*/
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
