<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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

        $projectId = $this->getContainer()->getParameter('google_project_id');

        $topicName = $this->getContainer()->getParameter('google_pubsub_topicname');

        $output->writeln('<info>Setting up PubSub Topic</info>');
        $topic = new \Google_Service_Pubsub_Topic();
        $fullTopicName = 'projects/' . $projectId . '/topics/' . $topicName;

        try {
            $pubsub->projects_topics->create($fullTopicName, $topic);
            $output->writeln('Successfully set up PubSub Topic');
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() == '409') {
                $this->getContainer()->get('logger')->warning(
                    'Received 409 when attempting to add topic (already exists)',
                    array('name' => $fullTopicName)
                );

                // don't do anything
                $output->writeln('PubSub Topic was already set up, nothing to do');
            } else if ($e->getCode() == '401') {
                $formatter = $this->getHelper('formatter');
                $errorMessages = array('Error: Authentication failed', '', 'Are you sure you set the scopes correctly in the Google Admin interface?', 'See https://github.com/colinfrei/tomai#service-account for further instructions.');
                $formattedBlock = $formatter->formatBlock($errorMessages, 'error', true);
                $output->writeln($formattedBlock);

                return;
            } else {
                throw $e;
            }
        }

        $output->writeln('<info>Adding PubSub subscription</info>');
        $subscription = new \Google_Service_Pubsub_Subscription();
        $subscription->setName($projectId);
        $subscription->setTopic($fullTopicName);
        $subscriptionName = 'projects/' . $projectId . '/subscriptions/' . $topicName;
        try {
            $pubsub->projects_subscriptions->create($subscriptionName, $subscription);
            $output->writeln('Successfully added PubSub subscription');
        } catch (\Google_Service_Exception $e) {
            if ($e->getCode() == '409') {
                $this->getContainer()->get('logger')->warning(
                    'Received 409 when attempting to add subscription (already exists)',
                    array('name' => $topicName)
                );
                // don't do anything
                $output->writeln('Subscription was already set up, nothing to do');
            } else {
                throw $e;
            }
        }

        $output->writeln('<info>Adding Iam Policy to give GMail access to push notifications</info>');
        $setIamPolicyRequest = new \Google_Service_Pubsub_SetIamPolicyRequest();
        $iamPolicy = new \Google_Service_Pubsub_Policy();
        $iamPolicy->setBindings(array(
            'role' => "roles/pubsub.publisher",
            'members' => array("serviceAccount:gmail-api-push@system.gserviceaccount.com")
        ));
        $setIamPolicyRequest->setPolicy($iamPolicy);
        $pubsub->projects_subscriptions->setIamPolicy($fullTopicName, $setIamPolicyRequest);
        $output->writeln('Added Iam Policy');

        $questionHelper = $this->getHelper('question');
        $pushPullQuestion = new ChoiceQuestion(
            'Do you want to use Push or Pull to receive update notifications?<br />
In most cases you\'ll want to use push, pull is mostly good for dev environments',
            array('push', 'pull')
        );

        $pushPull = $questionHelper->ask($input, $output, $pushPullQuestion);

        if ('push' === $pushPull) {
            $output->writeln('<info>Setting up Push Configuration</info>');
            $pushConfig = new \Google_Service_Pubsub_PushConfig();
            $pushUrl = $this->getContainer()->get('router')->generate('google-push', array(), Router::ABSOLUTE_URL);

            $confirmUrlQuestion = new ConfirmationQuestion('Using the URL \'' . $pushUrl . '\' for pushes. Is that correct? ');
            if (!$questionHelper->ask($input, $output, $confirmUrlQuestion)) {
                $whatUrlToUseQuestion = new Question('What URL should be used instead? ');
                $pushUrl = $questionHelper->ask($input, $output, $whatUrlToUseQuestion);
            }
            $pushConfig->setPushEndpoint($pushUrl);

            $modifyPushConfigRequest = new \Google_Service_Pubsub_ModifyPushConfigRequest();
            $modifyPushConfigRequest->setPushConfig($pushConfig);
            $pubsub->projects_subscriptions->modifyPushConfig($subscriptionName, $modifyPushConfigRequest);
            $output->writeln('Added Push Config with URL ' . $pushUrl);
        } else {
            $output->writeln('Nothing further to do for Pull configuration');
        }

        $output->writeln('Done!');
    }
}
