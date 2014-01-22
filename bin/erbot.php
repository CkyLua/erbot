<?php
use Erpk\Harvester\Client\Client;
use Erpk\Harvester\Client\Proxy\HttpProxy;
use Erpk\Harvester\Client\Proxy\NetworkInterfaceProxy;
use Symfony\Component\Console\Application;
use ERBot\Command;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Guzzle\Log\MonologLogAdapter;
use Guzzle\Plugin\Log\LogPlugin;
use Guzzle\Log\MessageFormatter;

require __DIR__.'/../vendor/autoload.php';

if (!isset($configFile)) {
    $configFile = __DIR__.'/../config.json';
}

if (isset($useCopy) && $useCopy === true) {
    Erpk\Common\EntityManager::useCopy(true);
}

$bot = new Application('ERBot');
$bot->configFile = $configFile;
$bot->add(new Command\ConfigureCommand);

if (file_exists($configFile)) {
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
            $proxy = new HttpProxy($ex[0], $ex[1]);
            $client->setProxy($proxy);
        }

        if ($config['proxy.interface'] !== null) {
            $proxy = new NetworkInterfaceProxy($config['proxy.interface']);
            $client->setProxy($proxy);
        }

        $bot->erpkClient = $client;

        $bot->add(new Command\HunterCommand);
        $bot->add(new Command\RefillCommand);
        $bot->add(new Command\FighterCommand);
        $bot->add(new Command\TasksCommand);
        $bot->add(new Command\ForexCommand);
    }
}

$bot->run();
