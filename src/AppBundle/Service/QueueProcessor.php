<?php

namespace AppBundle\Service;

use AppBundle\Entity\EmailCopyJob;
use AppBundle\Entity\QueueMessage;
use AppBundle\Entity\User;
use AppBundle\Service\Google\GoogleOAuthClient;
use AppBundle\Service\Google\GroupsMigration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class QueueProcessor
{
    private $entityManager;
    private $googleClient;
    private $logger;
    private $groupsMigrationService;

    public function __construct(EntityManagerInterface $entityManager, GoogleOAuthClient $googleClient, LoggerInterface $logger, GroupsMigration $groupsMigrationService)
    {
        $this->entityManager = $entityManager;
        $this->googleClient = $googleClient;
        $this->logger = $logger;
        $this->groupsMigrationService = $groupsMigrationService;
    }

    private function getGoogleClient(User $user)
    {
        return $this->googleClient->getClient($user);
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

            $gmail = new \Google_Service_Gmail($this->getGoogleClient($user));
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
        $this->logger->debug('Handling message');
        $rfc822Message = $this->base64url_decode($message->getRaw());

        try {
            $this->groupsMigrationService->archive->insert($copy->getGroupEmail(), array(
                'data' => $rfc822Message,
                'mimeType' => 'message/rfc822',
                'uploadType' => 'media'
            ));
        } catch (\Google_Service_Exception $e) {
            $this->handleInsertException($e, $copy, $message, 1);
        }
    }

    private function handleInsertException(
        \Google_Service_Exception $e,
        EmailCopyJob $copy,
        \Google_Service_Gmail_Message $message,
        $count
    )
    {
        switch ($e->getCode()) {
            case 404:
                // possibly the group was deleted.
                $this->logger->error(
                    'Could not insert message into group, possibly group was deleted manually.',
                    array('groupEmail' => $copy->getGroupEmail())
                );
                break;

            case 413:
                $this->logger->info(
                    'Message could not be inserted into Google Group because it was too big.',
                    array(
                        'groupEmail' => $copy->getGroupEmail(),
                        'messageId' => $message->id
                    )
                );

                if ($count < 2) {
                    try {
                        $this->groupsMigrationService->archive->insert($copy->getGroupEmail(), array(
                            'data' => $this->buildRfc822Message($message),
                            'mimeType' => 'message/rfc822',
                            'uploadType' => 'media'
                        ));
                    } catch (\Google_Service_Exception $e) {
                        $this->handleInsertException($e, $copy, $message, 2);
                    }

                    break;
                }

            default:
                $this->logger->error($e);
                throw new \Exception('Could not insert message into group.');
        }
    }

    /**
     * Try and build a "valid" message with only the text/plain parts, as a fallback
     * for when the raw message can't be handled correctly
     *
     * @param \Google_Service_Gmail_Message $message
     *
     * @return string
     */
    private function buildRfc822Message(\Google_Service_Gmail_Message $message)
    {
        $messagePayload = $message->getPayload();
        $headers = array();
        $bodyData = '';
        foreach ($messagePayload->getHeaders() as $header) {
            $headers[$header['name']] = $header['value'];
        }
        foreach ($messagePayload->getParts() as $part) {
            if ($part->getMimeType() != 'text/plain') {
                continue;
            }
            foreach ($part->getHeaders() as $header) {
                $headers[$header['name']] = $header['value'];
            }
            $bodyData = base64_decode($part->getBody()->data);
        }
        $output = '';
        $setContentTransferEncodingHeader = false;
        foreach ($headers as $header => $value) {
            switch (strtolower($header)) {
                case 'content-transfer-encoding':
                    $this->logger->debug('Replaced content-transfer-encoding header', array('original' => $value));
                    $value = 'quoted-printable';
                    $setContentTransferEncodingHeader = true;
                    break;
                case 'content-type':
                    $contentTypeParts = explode(';', $value);
                    foreach ($contentTypeParts as $key => $contentTypePart) {
                        $contentTypePart = trim($contentTypePart);
                        $searchString = 'charset=';
                        if (strtolower(substr($contentTypePart, 0, strlen($searchString))) == $searchString) {
                            $contentTypeParts[$key] = 'charset="UTF-8"';
                        }
                    }
                    $newValue = implode('; ', $contentTypeParts);
                    $this->logger->debug(
                        'Replaced content-type header',
                        array('original' => $value, 'replaced with' => $newValue)
                    );
                    $value = $newValue;
                    break;
            }
            $output .= $header . ': ' . $value . "\r\n";
        }
        if (!$setContentTransferEncodingHeader) {
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
        }
        $output .= "\r\n" . quoted_printable_encode($bodyData);
        return $output;
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

        // Ignore drafts and any ignore labels on the copy
        $ignoredLabels = array_merge($copy->getIgnoredLabels(), array('DRAFT'));

        $ignoredMatchCount = count(array_intersect($ignoredLabels, $messageLabelIds));

        if ($ignoredMatchCount >= 1) {

            return false;
        }

        if ($messageDate < $copy->getStartDate()) {
            return false;
        }

        return true;
    }
}
