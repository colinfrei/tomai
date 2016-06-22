<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RenewGmailWatchesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('tomai:renew-gmail-watches')
            ->setDescription('Renew Gmail watches, since they expire automatically after 7 days')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // get all copy jobs and renew each one
        $copyJobs = $this->getContainer()->get('doctrine.orm.default_entity_manager')->getRepository('AppBundle:EmailCopyJob')->findMessagesWithUserForList();

        foreach ($copyJobs as $copy) {
            $this->getContainer()->get('service.gmail_watch_helper')->addGmailWatch($copy);
        }

        $output->writeln('Done!');
    }
}
