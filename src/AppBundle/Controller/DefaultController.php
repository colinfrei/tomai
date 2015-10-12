<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Copy;
use AppBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Router;

class DefaultController extends Controller
{
    private $googleClient;
    private $groupsMigrationClient;

    private function getGoogleClient(User $user = null)
    {
        if (!isset($this->googleClient)) {
            $this->googleClient = $this->get('happyr.google.api.client');

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

    private function getGroupsMigrationClient()
    {
        if (!isset($this->groupsMigrationClient)) {
            $this->groupsMigrationClient = new \Google_Service_GroupsMigration($this->getGoogleClient()->getGoogleClient());
        }

        return $this->groupsMigrationClient;
    }

    private function getLogger()
    {
        return $this->get('logger');
    }

    /**
     * @return EntityManagerInterface
     */
    private function getEntityManager()
    {
        return $this->get('doctrine.orm.default_entity_manager');
    }

    /**
     * @Route("/", name="index")
     * @Method({"GET"})
     */
    public function indexAction()
    {
        return $this->render('default/index.html.twig');
    }

    /**
     * @Route("/manage", name="manage-copyjobs")
     */
    public function manageCopyjobsAction(Request $request)
    {
        $gmail = new \Google_Service_Gmail($this->getGoogleClient()->getGoogleClient());

        $labels = $gmail->users_labels->listUsersLabels($this->getUser()->getGoogleId());
        $formLabels = [];
        /** @var \Google_Service_Gmail_Label $label */
        foreach ($labels->getLabels() as $label) {
            if ('labelHide' === $label->getLabelListVisibility()) {
                continue;
            }

            $formLabels[$label->getId()] = $label->getName();
        }

        asort($formLabels);

        $viewCopies = [];

        /** @var Copy $copy */
        foreach ($this->getUser()->getCopies() AS $copy) {
            $labelNames = [];
            foreach ($copy->getLabels() as $labelId) {
                $labelNames[] = $formLabels[$labelId];
            }
            $viewCopies[] = array(
                'id' => $copy->getId(),
                'name' => $copy->getName(),
                'labelNames' => $labelNames,
                'googleGroupUrl' => $copy->getGroupUrl()
            );
        }

        $copy = new Copy();
        $form = $this->createFormBuilder($copy)
            ->add('name', 'text', array(
                'help' => 'This will be also be the name (not the email address) of the Google Group.<br />Use something that makes sense out of context, like "Colin\'s Client A emails"'
            ))
            ->add('labels', 'choice', array(
                'choices' => $formLabels,
                'required' => true,
                'multiple' => true,
                'attr' => array('class' => 'chosen')
            ))
            ->add('save', 'submit')
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            $copy->setUser($this->getUser());
            $copy->setUserId($this->getUser()->getId());

            $this->addGoogleGroup($copy);
            $this->getEntityManager()->persist($copy);
            $this->getEntityManager()->flush();

            $this->addGmailWatch($copy);

            //TODO:
            // - trigger initial import
            // - make sure regular imports happen
            //return $this->redirectToRoute('setup_success');
        }

        // replace this example code with whatever you need
        return $this->render('default/manage.html.twig', array(
            'copies' => $viewCopies,
            'form' => $form->createView()
        ));
    }

    private function addGoogleGroup(Copy $copy)
    {
        $directoryClient = new \Google_Service_Directory($this->getGoogleClient()->getGoogleClient());

        $userEmail = $copy->getUser()->getEmail();
        $group = new \Google_Service_Directory_Group();
        $groupId = uniqid('tomai-');
        $group->setEmail($groupId . '@' . $this->getParameter('google_apps_domain'));
        $group->setDescription('Tomai-generated group by ' . $userEmail);
        $groupResponse = $directoryClient->groups->insert($group);

        $copy->setGroupEmail($groupResponse->getEmail());
        $copy->setGroupUrl('https://groups.google.com/a/' . $this->getParameter('google_apps_domain') . '/forum/#!forum/' . $groupId);
        // Copy is saved after. not nice.
    }

    /**
     * @Route("/deletecopy/{id}", name="delete-copy")
     */
    public function deleteCopyAction(Request $request, $id)
    {
        /** @var Copy $copy */
        $copy = $this->getEntityManager()->getRepository('AppBundle:Copy')->find($id);

        if ($copy->getUser() != $this->getUser()) {
            exit('Invalid User');
        }

        $directoryClient = new \Google_Service_Directory($this->getGoogleClient()->getGoogleClient());

        $directoryClient->groups->delete($copy->getGroupEmail());

        $this->getEntityManager()->remove($copy);
        $this->getEntityManager()->flush();

        // replace this example code with whatever you need
        return $this->render('default/index.html.twig', array(
            'base_dir' => realpath($this->container->getParameter('kernel.root_dir') . '/..'),
        ));
    }

    private function handleMessage(\Google_Service_Gmail_Message $message, Copy $copy)
    {
        $rfc822Message = $this->buildRfc822Message($message);
        try {
            $this->getGroupsMigrationClient()->archive->insert($copy->getGroupEmail(), array(
                'data' => $rfc822Message,
                'mimeType' => 'message/rfc822',
                'uploadType' => 'media'
            ));
        } catch (\Google_Service_Exception $e) {
            $this->getLogger()->error($e);
            exit;
        }
    }

    /**
     * @Route("/pull", name="pull-messages")
     */
    public function pullMessagesAction(Request $request)
    {
        $pubsub = new \Google_Service_Pubsub($this->getGoogleClient()->getGoogleClient());

        $pullRequest = new \Google_Service_Pubsub_PullRequest();
        $pullRequest->setMaxMessages(20);

        $subscriptionUrl = 'projects/1234/subscriptions/adfhaerg'; //TODO: config this

        $pullResponse = $pubsub->projects_subscriptions->pull($subscriptionUrl, $pullRequest);
        /** @var \Google_Service_Pubsub_ReceivedMessage $receivedMessage */
        foreach($pullResponse->getReceivedMessages() as $receivedMessage) {
            $message = json_decode(base64_decode($receivedMessage->getMessage()->getData()), true);
            $user = $this->getEntityManager()->getRepository('AppBundle:User')->findOneBy(array('email' => $message['emailAddress']));
            $this->getLogger()->debug('Processing Google Pubsub Message', $message);

            $this->processHistory($user, $user->getGmailHistoryId());
            $user->setGmailHistoryId($message['historyId']);

            $this->getEntityManager()->persist($user);
            $this->getEntityManager()->flush();

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
        $messageData = json_decode($request->getContent());
        $message = json_decode(base64_decode($messageData->message->data), true);
        /** @var User $user */
        $user = $this->getEntityManager()->getRepository('AppBundle:User')->findOneBy(array('email' => $message['emailAddress']));


        $this->getLogger()->debug('Processing Google Pubsub Message', $message);

        $this->processHistory($user, $user->getGmailHistoryId());
        $user->setGmailHistoryId($message['historyId']);

        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();

        return new Response('', 204);
    }

    private function processHistory(User $user, $historyId)
    {
        $gmail = new \Google_Service_Gmail($this->getGoogleClient($user)->getGoogleClient());

        $history = $this->listHistory($gmail, $user->getEmail(), $historyId);
        /** @var \Google_Service_Gmail_History $historyPart */
        foreach ($history as $historyPart) {
            $this->getLogger()->debug('Processing history part', array('content' => print_r($historyPart, true)));

            foreach ($user->getCopies() as $copy) { //TODO: move this outside foreach loop and use all the users labels for history filter
                /** @var \Google_Service_Gmail_HistoryLabelAdded $historyMessage */
                foreach ($historyPart->getLabelsAdded() as $historyMessage) {
                    if (count(array_intersect($copy->getLabels(), $historyMessage->getLabelIds())) > 0) {
                        $actualMessage = $gmail->users_messages->get(
                            $user->getEmail(),
                            $historyMessage->getMessage()->getId()
                        );

                        $this->handleMessage($actualMessage, $copy);
                    }
                }

                /** @var \Google_Service_Gmail_HistoryMessageAdded $historyMessage */
                foreach ($historyPart->getMessagesAdded() as $historyMessage) {
                    //TODO: deduplicate
                    if (count(array_intersect($copy->getLabels(), $historyMessage->getMessage()->labels)) > 0) {
                        $actualMessage = $gmail->users_messages->get(
                            $user->getEmail(),
                            $historyMessage->getMessage()->getId()
                        );

                        $this->handleMessage($actualMessage, $copy);
                    }
                }
            }
        }
    }

    private function addGmailWatch(Copy $copy)
    {
        $topicName = 'projects/email-copier/topics/test1'; //TODO: make this come from config

        $gmail = new \Google_Service_Gmail($this->getGoogleClient()->getGoogleClient());
        $watchRequest = new \Google_Service_Gmail_WatchRequest();
        $watchRequest->setTopicName($topicName);

        // Let's just handle the filtering on our side, makes it a bit easier
        //$watchRequest->setLabelIds($copy->getLabels());
        $watchResponse = $gmail->users->watch($this->getUser()->getGoogleId(), $watchRequest);

        $copyUser = $copy->getUser();
        if (!$copyUser->getGmailHistoryId()) {
            $copyUser->setGmailHistoryId($watchResponse->getHistoryId());
            $this->getEntityManager()->persist($copyUser);
            $this->getEntityManager()->flush();
        }
    }

    private function buildRfc822Message(\Google_Service_Gmail_Message $message)
    {
        $messagePayload = $message->getPayload();

        $headers = array();
        foreach ($messagePayload->getHeaders() as $header) {
            $headers[$header['name']] = $header['value'];
        }

        if ($messagePayload->getBody()->size > 0) {
            $bodyData = base64_decode($messagePayload->getBody()->data);
        } else {
            foreach ($messagePayload->getParts() as $part) {
                if ($part->getMimeType() != 'text/plain') {
                    continue;
                }

                foreach ($part->getHeaders() as $header) {
                    $headers[$header['name']] = $header['value'];
                }

                $bodyData = base64_decode($part->getBody()->data);
            }
        }

        $output = '';
        $setContentTransferEncodingHeader = false;
        foreach ($headers as $header => $value) {
            switch (strtolower($header)) {
                case 'content-transfer-encoding':
                    $this->get('logger')->debug('Replaced content-transfer-encoding header', array('original' => $value));
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

                    $this->get('logger')->debug(
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
