<?php
namespace ERBot\Command;

use Exception;
use RuntimeException;
use Erpk\Harvester\Module\Management\ManagementModule;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class RefillCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('refill')
            ->setDescription('Refill energy to keep it full.');
    }

    protected function eat()
    {
        $client = $this->getApplication()->getErpkClient();
        $management = new ManagementModule($client);
        $status = $management->getEnergyStatus();

        if ($status['energy'] < $status['max_energy'] && $status['food_recoverable_energy'] > 0) {
            $result = $management->eat();
            return 'Health: '.$result['health'];
        } else {
            return 'Energy already maximum or no food in inventory';
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->writeln('<info>'.$this->eat().'</info>');
        } catch (Exception $e) {
            $output->writeln(
                '<error>'.$e->getMessage().'</error>'
            );
        }
    }
}
