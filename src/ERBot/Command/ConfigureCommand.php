<?php
namespace ERBot\Command;

use Erpk\Harvester\Module\Military\MilitaryModule;
use Erpk\Harvester\Module\Management\ManagementModule;
use Erpk\Common\EntityManager;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('configure')
            ->setDescription('Configure eRepublik account.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFields = array(
            'email' => ['eRepublik e-mail', false],
            'password' => ['eRepublik password', false],
            'user.agent' => ['User agent (default if empty)', null],
            'proxy.http' => ['HTTP proxy (IP:port:username:password)', null],
            'proxy.interface' => ['Interface proxy (interface name)', null],
        );

        $dialog = $this->getHelperSet()->get('dialog');
        $config = array();
        foreach ($configFields as $id => $field) {
            list($label, $defaultValue) = $field;

            $config[$id] = $dialog->ask(
                $output,
                $label.': ',
                $defaultValue
            );
        }

        file_put_contents(
            $this->getApplication()->configFile,
            json_encode($config)
        );
        
        $output->writeln('Configuration updated.');
    }
}
