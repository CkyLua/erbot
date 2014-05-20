<?php
namespace ERBot;

use Erpk\Harvester\Client\Client;
use Erpk\Harvester\Client\Proxy\HttpProxy;
use Erpk\Harvester\Client\Proxy\NetworkInterfaceProxy;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends SymfonyApplication
{
    protected $erpkClient;
    protected $configPath;

    public function __construct($dirname)
    {
        date_default_timezone_set('America/Los_Angeles');
        parent::__construct('ERBot', 'UNKNOWN');

        $this->add(new Command\ConfigureCommand());

        $this->getDefinition()->addOptions([
            new InputOption('--config', '-c', InputOption::VALUE_REQUIRED, 'Path to the JSON config file.', $dirname.'/config.json'),
            new InputOption('--delay', '-d', InputOption::VALUE_REQUIRED, 'Delay execution of the script.', 0),
        ]);
    }

    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $input->bind($this->getDefinition());

        $delay = filter_var($input->getOption('delay'), FILTER_VALIDATE_INT);
        if ($delay > 0) {
            $wakeupAt = time() + mt_rand(1, $delay);
            $output->writeln('<comment>Waiting until '.date('Y-m-d H:i:s', $wakeupAt).' eRepublik time.</comment>');
            time_sleep_until($wakeupAt);
        }

        $this->configPath = $input->getOption('config');
        $this->loadConfig();

        parent::doRun($input, $output);
    }

    public function getErpkClient()
    {
        return $this->erpkClient;
    }

    public function getConfigPath()
    {
        return $this->configPath;
    }

    protected function loadConfig()
    {
        $configFile = $this->getConfigPath();

        if (!file_exists($configFile)) {
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);
        $testConfig = function ($keys) use ($config) {
            $result = true;
            foreach ($keys as $key) {
                if (!array_key_exists($key, $config)) {
                    $result = false;
                }
            }
            return $result;
        };

        $result = $testConfig(['email', 'password', 'user.agent', 'proxy.http']);

        if ($result) {
            $client = new Client;
            $client->setEmail($config['email']);
            $client->setPassword($config['password']);
            $client->setUserAgent($config['user.agent']);

            if ($config['user.agent'] !== null) {
                $client->setUserAgent($config['user.agent']);
            }

            if ($config['proxy.http'] !== null) {
                $ex = explode(':', $config['proxy.http']);

                $proxy = new HttpProxy(
                    $ex[0],
                    $ex[1],
                    isset($ex[2]) ? $ex[2]: null,
                    isset($ex[3]) ? $ex[3]: null
                );
                
                $client->setProxy($proxy);
            }

            if ($config['proxy.interface'] !== null) {
                $proxy = new NetworkInterfaceProxy($config['proxy.interface']);
                $client->setProxy($proxy);
            }

            $this->erpkClient = $client;

            $this->addCommands([
                new Command\HunterCommand(),
                new Command\FighterCommand(),
                new Command\TasksCommand(),
                new Command\ForexCommand(),
                new Command\RefillCommand()
            ]);
        }
    }
}
