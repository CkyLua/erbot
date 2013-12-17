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
            ->setDescription('Refill energy to keep it full.')
            ->addOption(
                'once',
                null,
                InputOption::VALUE_NONE,
                'Set this option if command has to be executed just once'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_REQUIRED,
                'Interval between refills in seconds',
                2700
            )
            ->addOption(
                'reserve',
                'r',
                InputOption::VALUE_REQUIRED,
                'Safety time reserve between updates (in %)',
                20
            );
    }

    protected function eat()
    {
        $client = $this->getApplication()->erpkClient;
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
        $once = $input->getOption('once');

        $interval = filter_var($input->getOption('interval'), FILTER_VALIDATE_INT);
        if (!$interval || $interval <= 0) {
            throw new RuntimeException('Invalid time interval. Please specify amount of seconds.');
        }

        $reserve = filter_var($input->getOption('reserve'), FILTER_VALIDATE_INT);
        if (!$reserve || $reserve < 0) {
            throw new RuntimeException('Invalid time reserve. Please specify valid reserve in %.');
        }

        $deflection = $reserve / 100 * $interval;

        while (true) {
            $waitSeconds = $interval + mt_rand(-$deflection, $deflection);

            $wakeupAt = time() + $waitSeconds;
            $output->writeln('<comment>Waiting until '.gmdate('r', $wakeupAt).'.</comment>');
            time_sleep_until($wakeupAt);

            try {
                $output->writeln('<info>'.$this->eat().'</info>');
            } catch (Exception $e) {
                $output->writeln(
                    '<error>'.$e->getMessage().'</error>'
                );
            }
            
            if ($once) {
                break;
            }
        }
    }
}
