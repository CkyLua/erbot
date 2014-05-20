<?php
namespace ERBot\Command;

use Erpk\Harvester\Module\Market\MarketModule;
use Erpk\Harvester\Module\Management\ManagementModule;
use Erpk\Common\EntityManager;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class HunterCommand extends Command
{
    protected $client;
    protected $marketModuleModule;
    protected $managementModule;
    protected $output;
    protected $loader;
    protected $waitingLoader = 1;
    protected $lastLoadTime = null;

    protected $toBuy = 0;
    protected $priceLimit;
    protected $country;
    protected $industry;
    protected $quality;
    protected $minimumAmount;
    protected $storageLastUpdate = null;
    protected $storageUpdateInterval = 300; // 10 minutes

    protected function configure()
    {
        $this
            ->setName('hunter')
            ->setDescription('Buy products at desired price.')
            ->addArgument(
                'country',
                InputArgument::REQUIRED,
                'Country code to buy in.'
            )
            ->addArgument(
                'industry',
                InputArgument::REQUIRED,
                'Industry: food, weapons, wrm, frm.'
            )
            ->addArgument(
                'quality',
                InputArgument::REQUIRED,
                'Product quality (set 1 for raw materials)'
            )
            ->addArgument(
                'price',
                InputArgument::REQUIRED,
                'Price for unit to buy at. Ex. 0.02 or 9.85.'
            )
            ->addArgument(
                'amount',
                InputArgument::REQUIRED,
                'Amount of products to buy.'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Interval between checks in seconds.',
                2
            )->addOption(
                'minimum',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Minimum amount of products in offer to buy.',
                1
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->loader = str_split('|/-\|/-\\');

        $this->client = $this->getApplication()->getErpkClient();
        $this->managementModule = new ManagementModule($this->client);
        $this->marketModule = new MarketModule($this->client);

        $this->priceLimit = filter_var($input->getArgument('price'), FILTER_VALIDATE_FLOAT);
        if (!$this->priceLimit) {
            throw new Exception('Price is not a valid number.');
        }

        $this->scanInterval = filter_var($input->getOption('interval'), FILTER_VALIDATE_FLOAT);
        if (!$this->scanInterval) {
            throw new Exception('Interval is not a valid number.');
        }

        $this->amountToRefill = filter_var($input->getArgument('amount'), FILTER_VALIDATE_INT);
        if (!$this->amountToRefill) {
            throw new Exception('Amount is not a valid number.');
        }

        $em = EntityManager::getInstance();
        $countries = $em->getRepository('Erpk\Common\Entity\Country');
        $industries = $em->getRepository('Erpk\Common\Entity\Industry');

        $this->country = $countries->findOneByCode($input->getArgument('country'));
        if (!$this->country) {
            throw new Exception('Country code is invalid.');
        }

        $this->industry = $industries->findOneByCode($input->getArgument('industry'));
        if (!$this->industry) {
            throw new Exception('Industry is invalid. Available options: food, weapons, wrm, frm.');
        }

        $this->quality = filter_var($input->getArgument('quality'), FILTER_VALIDATE_INT);
        if (!$this->quality) {
            throw new Exception('Quality is not a valid number.');
        }

        $isRawMaterial = $this->industry->getCode() === 'wrm' || $this->industry->getCode() === 'frm';
        if ($isRawMaterial) {
            if ($this->quality !== 1) {
                throw new Exception('Quality for raw materials must be 1.');
            }
        } else if ($this->quality < 1 || $this->quality > 7) {
            throw new Exception('Quality must be between 1 and 7.');
        }
        
        $this->minimumAmount = (int)$input->getOption('minimum');

        $this->hunter();
    }

    protected function hunter()
    {
        while (true) {
            $this->updateStorageStatus();

            if ($this->toBuy > 0) {
                $this->scan();
            }

            usleep($this->scanInterval*1000000);
        }
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

    protected function updateStorageStatus()
    {
        $output = $this->output;
        $now = time();
        $needsUpdate =
            $this->storageLastUpdate === null ||
            ($this->storageUpdateInterval <= $now-$this->storageLastUpdate);

        if ($needsUpdate === false) {
            return;
        }

        $output->writeln('<comment>Updating the storage...</comment>'."\r");

        $this->toBuy = $this->amountToRefill;
        $inventory = $this->managementModule->getInventory();
        $this->storageLastUpdate = $now;
        if (!isset($inventory['items'][$this->industry->getId()][$this->quality])) {
            $inStorage = 0;
        } else {
            $inStorage = (int)$inventory['items'][$this->industry->getId()][$this->quality];
        }

        $this->toBuy -= $inStorage;

        if ($this->toBuy > 0) {
            $output->writeln(
                '<comment>You already have '.$inStorage.' of '.$this->industry->getName().' in storage,'.
                ' so you need to buy only: '.$this->toBuy.'.</comment>'
            );

            $availableSlots = $inventory['storage']['maximum'] - $inventory['storage']['current'];

            if ($this->toBuy > $availableSlots) {
                $this->toBuy = $availableSlots;
                $output->writeln(
                    '<comment>Unfortunately, you have only '.$availableSlots.' slots available in storage.</comment>'
                );

                $output->writeln(
                    '<comment>You can buy maximum '.$this->toBuy.' of products.</comment>'
                );
            }
        } else {
            $this->toBuy = 0;
            $output->writeln(
                '<comment>You already have '.$inStorage.' of '.$this->industry->getName().' in storage.</comment>'
            );

            $output->writeln(
                '<comment>Waiting '.$this->storageUpdateInterval.' seconds until next storage update.</comment>'
            );

            sleep($this->storageUpdateInterval);
        }
    }

    protected function scan()
    {
        try {
            $offers = $this->marketModule->scan(
                $this->country,
                $this->industry,
                $this->quality
            );
        } catch (\Exception $e) {
            $this->output->writeln('<error>'.$e->getMessage().'</error>');
            return;
        }
        
        $first = true;
        foreach ($offers as $offer) {
            if ($offer->price <= $this->priceLimit && $offer->amount >= $this->minimumAmount) {
                $startTime = microtime(true);
                $this->output->writeln('<info>Found offer: [ID: '.$offer->id.'][Amount: '.$offer->amount.']</info>');
                $amount = $offer->amount;
                while ($this->toBuy > 0 && $amount > 0) {
                    if ($amount < 100000) {
                        if ($amount > $this->toBuy) {
                            $n = $this->toBuy;
                        } else {
                            $n = $amount;
                        }
                    } else {
                        if ($this->toBuy < 100000) {
                            $n = $this->toBuy;
                        } else {
                            $n = 99999;
                        }
                    }

                    $result = $this->marketModule->buy($offer, $n);
                    $msPassed = round((microtime(true)-$startTime)*1000, 0);

                    $successful = fnmatch('You have successfully bought*', $result);
                    if ($successful) {
                        $amount -= $n;
                        $this->toBuy -= $n;
                    }

                    $this->output->writeln('<info>['.$msPassed.' ms passed]['.$result.'][Left: '.$this->toBuy.']</info>');

                    if (!$successful) {
                        break;
                    }
                }
            } elseif ($first) {
                $first = false;
                $this->pingLoader();
            }
        }
    }
}
