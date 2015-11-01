<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Routing\Router;

class ProcessQueueCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('tomai:process-queue')
            ->setDescription('Process Message Queue')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'The number of minutes ago that messages should be processed', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $xMinutesAgo = intval($input->getOption('since'));
        $time = new \DateTime($xMinutesAgo . ' minutes ago');

        $this->getContainer()->get('service.queue_processor')->process($time);

        $output->writeln('Done!');
    }
}
