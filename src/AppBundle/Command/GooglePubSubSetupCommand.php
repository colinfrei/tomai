<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Routing\Router;

class GooglePubSubSetupCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('tomai:google-pubsub-setup')
            ->setDescription('Setup PubSub stuff')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // check if settings are still on default
        // if so, ask for confirmation

        $pubsub = $this->getContainer()->get('google.pubsub');
        // all the above might work since we don't have a google account set up yet

        $questionHelper = $this->getHelper('question');
        $projectIdQuestion = new Question('What\'s the Project ID of this project in Google (see https://console.developers.google.com/project)? ');
        $projectId = $questionHelper->ask($input, $output, $projectIdQuestion);

        $projectNumberQuestion = new Question('What\'s the Project Number of this project in Google? (go to https://console.developers.google.com/project and then click on the project) ');
        $projectNumber = $questionHelper->ask($input, $output, $projectNumberQuestion);

        $topicNameQuestion = new Question('What topic name should we use for the PubSub Subscription? Usually you can leave this on the default, unless you have multiple instances of Tomai set up on the same domain. ', 'tomai');
        $topicName = $questionHelper->ask($input, $output, $topicNameQuestion);
        $projectId = 'email-copier';
        $topicName = 'tomai123';

        $output->writeln('<info>Setting up PubSub Topic</info>');
        $topic = new \Google_Service_Pubsub_Topic();
        $fullTopicName = 'projects/' . $projectId . '/topics/' . $topicName;
        try {
            $pubsub->projects_topics->create($fullTopicName, $topic);
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() == '409') {
                $this->getContainer()->get('logger')->debug(
                    'Received 409 when attempting to add topic (already exists)',
                    array('name' => $fullTopicName)
                );
                // don't do anything
                //TODO: error? warning?
            }
        }

        $output->writeln('<info>Adding PubSub subscription</info>');
        $subscription = new \Google_Service_Pubsub_Subscription();
        $subscription->setName($projectId);
        $subscription->setTopic($topicName);
        $subscriptionName = 'projects/' . $projectNumber . '/subscriptions/' . $projectId;
        try {
            $pubsub->projects_subscriptions->create('projects/' . $projectNumber . '/subscriptions/' . $projectId, $subscription);
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() == '409') {
                $this->getContainer()->get('logger')->debug(
                    'Received 409 when attempting to add topic (already exists)',
                    array('name' => $topicName)
                );
                // don't do anything
                //TODO: error? warning?
            }
        }

        $output->writeln('<info>Giving GMail access to push notifications</info>');
        $setIamPolicyRequest = new \Google_Service_Pubsub_SetIamPolicyRequest();
        $iamPolicy = new \Google_Service_Pubsub_Policy();
        $iamPolicy->setBindings(array(
            'role' => "roles/pubsub.publisher",
            'members' => array("serviceAccount:gmail-api-push@system.gserviceaccount.com")
        ));
        $setIamPolicyRequest->setPolicy($iamPolicy);
        $pubsub->projects_subscriptions->setIamPolicy($subscription, $setIamPolicyRequest);


        $pushPullQuestion = new ChoiceQuestion(
            'Do you want to use Push or Pull to receive update notifications?<br />
In most cases you\'ll want to use push, pull is mostly good for dev environments',
            array('push', 'pull')
        );

        $pushPull = $questionHelper->ask($input, $output, $pushPullQuestion);

        if ('push' === $pushPull) {
            $output->writeln('<info>Setting up Push Configuration</info>');
            $pushConfig = new \Google_Service_Pubsub_PushConfig();
            //TODO: confirm URL
            $pushUrl = $this->getContainer()->get('router')->generate('google-push', array(), Router::ABSOLUTE_URL);
            //$pushUrl = 'https://tomai.liip.ch/google-push';
            $pushConfig->setPushEndpoint($pushUrl);

            $modifyPushConfigRequest = new \Google_Service_Pubsub_ModifyPushConfigRequest();
            $modifyPushConfigRequest->setPushConfig($pushConfig);
            $pubsub->projects_subscriptions->modifyPushConfig($subscriptionName, $modifyPushConfigRequest);
        }

        $output->writeln('Done!');
    }
}
