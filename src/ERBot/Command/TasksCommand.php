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
            ->setDescription('Complete daily tasks.')
            ->addOption(
                'train2',
                null,
                InputOption::VALUE_NONE,
                'Train in Climbing Center (0.19g)'
            )
            ->addOption(
                'train3',
                null,
                InputOption::VALUE_NONE,
                'Train in Shooting Range (0.89g)'
            )
            ->addOption(
                'train4',
                null,
                InputOption::VALUE_NONE,
                'Train in Special Forces Center (1.79g)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->getApplication()->getErpkClient();
        $mgmt = new ManagementModule($client);

        $output->write('Working... ');
        $result = $mgmt->workAsEmployee();
        $output->writeln($result['status'] ? 'OK' : 'error ('.$result['message'].')');
        sleep(5);

        $output->write('Training... ');
        $result = $mgmt->train(
            true,
            $input->getOption('train2'),
            $input->getOption('train3'),
            $input->getOption('train4')
        );
        $output->writeln($result['status'] ? 'OK' : 'error ('.$result['message'].')');

        sleep(5);
        $output->write('Getting daily rewards... ');
        $result = $mgmt->getDailyTasksReward();
        $output->writeln($result['message']);
    }
}
