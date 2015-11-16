<?php

namespace AppBundle\Service;

use AppBundle\Entity\EmailCopyJob;
use AppBundle\Entity\QueueMessage;
use AppBundle\Entity\User;
use AppBundle\Service\Google\GroupsMigration;
use Doctrine\ORM\EntityManagerInterface;
use HappyR\Google\ApiBundle\Services\GoogleClient;
use Psr\Log\LoggerInterface;

class QueueProcessor
{
    private $entityManager;
    private $realGoogleClient;
    private $googleClient;
    private $logger;
    private $groupsMigrationService;

    public function __construct(EntityManagerInterface $entityManager, GoogleClient $googleClient, LoggerInterface $logger, GroupsMigration $groupsMigrationService)
    {
        $this->entityManager = $entityManager;
        $this->realGoogleClient = $googleClient;
        $this->logger = $logger;
        $this->groupsMigrationService = $groupsMigrationService;
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

    public function process(\DateTime $from = null)
    {
        if (!$from) {
            $from = new \DateTime('5 minutes ago');
        }

        $fromMicroTimestamp = $from->getTimestamp() * 1000;

        $messages = $this->entityManager->getRepository('AppBundle:QueueMessage')
            ->findMessagesOlderThanX($fromMicroTimestamp);

        if (!$messages) {
            return;
        }

        $userEmails = [];
        /** @var QueueMessage $message */
        foreach ($messages as $message) {
            $userEmails[] = $message->getGoogleEmail();
        }

        $users = $this->entityManager->getRepository('AppBundle:User')
            ->findUsersWithCopiesByEmail(array_unique($userEmails));

        $usersByEmail = [];
        /** @var User $user */
        foreach ($users as $user) {
            $userEmail = $user->getEmail();
            $usersByEmail[$userEmail] = $user;
        }

        foreach ($messages as $message) {
            $user = $usersByEmail[$message->getGoogleEmail()];
            if (!$user) {
                throw new \Exception('trying to process message for user that doesn\'t exist');
            }

            $gmail = new \Google_Service_Gmail($this->getGoogleClient($user)->getGoogleClient());
            try {
                /** @var \Google_Service_Gmail_Message $actualMessage */
                $actualMessage = $gmail->users_messages->get(
                    $message->getGoogleEmail(),
                    $message->getMessageId(),
                    array('format' => 'raw')
                );
            } catch (\Google_Service_Exception $e) {
                switch ($e->getCode()) {
                    case '404':
                        $this->logger->error(
                            'Could not find gmail message',
                            array(
                                'errorMessage' => $e->getMessage(),
                                'messageId' => $message->getMessageId(),
                                'messageEmail' => $message->getGoogleEmail()
                            )
                        );
                    break;

                    case '403':
                        $this->logger->error(
                            'Wrong permissions',
                            array(
                                'errorMessage' => $e->getMessage(),
                                'messageId' => $message->getMessageId(),
                                'messageEmail' => $message->getGoogleEmail()
                            )
                        );
                    break;

                    default:
                        throw $e;
                        // this won't fall through to the $em->remove()
                }

                $this->entityManager->remove($message);
                continue;
            }

            $thread = $gmail->users_threads->get(
                $message->getGoogleEmail(),
                $actualMessage->getThreadId()
            );

            $labels = [];
            /** @var \Google_Service_Gmail_Message $siblingMessage */
            foreach ($thread->getMessages() as $siblingMessage) {
                $labels = array_merge($labels, $siblingMessage->getLabelIds());
            }

            foreach ($user->getCopies() as $copy) {
                $messageInternalDateSeconds = $actualMessage->getInternalDate()/1000;
                $messageDate = new \DateTime("@" . (int)$messageInternalDateSeconds);
                if (!$this->shouldMessageBeHandled($copy, $labels, $messageDate)) {
                    $this->logger->debug(
                        'Skipped message for copy because it didn\'t match any relevant labels or had an ignored label',
                        array(
                            'copy id' => $copy->getId(),
                            'message id' => $actualMessage->id,
                            'message label ids' => $actualMessage->getLabelIds(),
                            'copy label ids' => $copy->getLabels(),
                            'copy ignored label ids' => $copy->getIgnoredLabels()
                        )
                    );

                    continue;
                }

                $this->handleMessage($actualMessage, $copy);
            }

            $this->entityManager->remove($message);
        }

        $this->entityManager->flush();
    }

    private function handleMessage(\Google_Service_Gmail_Message $message, EmailCopyJob $copy)
    {
        $rfc822Message = $this->base64url_decode($message->getRaw());

        try {
            $this->groupsMigrationService->archive->insert($copy->getGroupEmail(), array(
                'data' => $rfc822Message,
                'mimeType' => 'message/rfc822',
                'uploadType' => 'media'
            ));
        } catch (\Google_Service_Exception $e) {
            $this->logger->error($e);
            throw new \Exception('Could not insert message into group.');
        }
    }

    private function base64url_decode($base64url)
    {
        $base64 = strtr($base64url, '-_', '+/');
        $plainText = base64_decode($base64);
        return ($plainText);
    }

    private function shouldMessageBeHandled(EmailCopyJob $copy, array $messageLabelIds, \DateTime $messageDate)
    {
        $matchCount = count(array_intersect($copy->getLabels(), $messageLabelIds));

        if ($matchCount < 1) {
            return false;
        }

        $ignoredMatchCount = count(array_intersect($copy->getIgnoredLabels(), $messageLabelIds));

        if ($ignoredMatchCount >= 1) {

            return false;
        }

        if ($messageDate < $copy->getStartDate()) {
            return false;
        }

        return true;
    }
}
