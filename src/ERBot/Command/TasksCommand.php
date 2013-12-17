<?php
namespace ERBot\Command;

use Erpk\Harvester\Module\Management\ManagementModule;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class TasksCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('tasks')
            ->setDescription('Complete daily tasks.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->getApplication()->erpkClient;
        $mgmt = new ManagementModule($client);
        $mgmt->workAsEmployee();
        sleep(5);
        $mgmt->train(true, true, true, true);
        sleep(5);
        $mgmt->getDailyTasksReward();
    }
}
