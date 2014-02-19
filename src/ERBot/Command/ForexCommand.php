<?php
namespace ERBot\Command;

use Erpk\Harvester\Module\Exchange\ExchangeModule;
use Erpk\Common\EntityManager;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ForexCommand extends Command
{
    protected $client;
    protected $exchangeModule;
    protected $managementModule;
    protected $output;
    protected $loader;
    protected $lastLoadTime = null;

    protected $priceLimit;

    protected function configure()
    {
        $this
            ->setName('forex')
            ->setDescription('Buy exchange offers.')
            ->addArgument(
                'buy',
                InputArgument::REQUIRED,
                'Values: cc, gold.'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Interval between checks in seconds.',
                2
            )
            ->addArgument(
                'price',
                InputArgument::REQUIRED,
                'Minimum price.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->loader = str_split('|/-\|/-\\');

        $this->client = $this->getApplication()->erpkClient;
        $this->exchangeModule = new ExchangeModule($this->client);

        $this->priceLimit = filter_var($input->getArgument('price'), FILTER_VALIDATE_FLOAT);
        if (!$this->priceLimit) {
            throw new Exception('Price is not a valid number.');
        }

        $this->scanInterval = filter_var($input->getOption('interval'), FILTER_VALIDATE_FLOAT);
        if (!$this->scanInterval) {
            throw new Exception('Interval is not a valid number.');
        }

        $this->buy =
            $input->getArgument('buy') == 'gold' ?
            ExchangeModule::GOLD : ExchangeModule::CURRENCY;

        switch ($input->getArgument('buy')) {
            case 'gold':
                $this->buy = ExchangeModule::GOLD;
                $this->sell = ExchangeModule::CURRENCY;
                break;
            case 'cc':
                $this->buy = ExchangeModule::CURRENCY;
                $this->sell = ExchangeModule::GOLD;
                break;
            default:
                throw new Exception('Invalid currency.');
        }

        $this->loop();
    }

    protected function pingLoader()
    {
        $output = $this->output;

        $loaderCurrent = next($this->loader);
        if (!$loaderCurrent) {
            $loaderCurrent = reset($this->loader);
        }

        $msg = '['.$loaderCurrent.']';
        $now = microtime(true);

        if ($this->lastLoadTime !== null) {
            $ms = (string)round(($now-$this->lastLoadTime)*1000, 0);
            $msg .= str_pad('['.$ms.' ms]', 20, ' ');
        }

        $this->lastLoadTime = $now;
        $output->write('<comment>'.$msg.'</comment>'."\r");
    }

    protected function loop()
    {
        $this->output->writeln('Starting...');

        while (true) {
            try {
                $offers = $this->exchangeModule->scan(
                    $this->buy
                );

                $this->pingLoader();

                $accounts = array(
                    ExchangeModule::CURRENCY => $offers->getCurrencyAmount(),
                    ExchangeModule::GOLD => $offers->getGoldAmount()
                );

                foreach ($offers as $offer) {
                    if ($offer->rate <= $this->priceLimit) {
                        $this->output->writeln(
                            "[ID: {$offer->id}][Amount: {$offer->amount}]".
                            "[Rate: {$offer->rate}][{$offer->sellerId}:{$offer->sellerName}]"
                        );

                        $toBuy = $offer->amount;

                        if ($this->buy == ExchangeModule::GOLD && $toBuy > 10) {
                            $toBuy = 10;
                        }

                        if ($toBuy * $offer->rate > $accounts[$this->sell]) {
                            $toBuy = round($accounts[$this->sell] / $offer->rate, 2) - 0.01;
                        }

                        if ($toBuy > 0) {
                            $this->buy($offer, $toBuy);
                        }
                    }
                }
            } catch (\Exception $e) {
                if ($this->output->isVerbose()) {
                    $this->output->writeln('<error>'.$e->getMessage().'</error>');
                }
            }
            usleep($this->scanInterval*1000000);
        }
    }

    protected function buy($offer, $toBuy)
    {
        $output = $this->output;
        $result = $this->exchangeModule->buy($offer->id, $toBuy);
        $output->writeln('<info>'.$result['message'].'</info>');

        if (preg_match('/buy more than ([\d.]+)/', $result['message'], $m)) {
            $toBuy = (float)$m[1];
            $result = $this->exchangeModule->buy($offer->id, $toBuy);
            $output->writeln('<info>'.$result['message'].'</info>');
        }
    }
}
