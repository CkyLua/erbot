<?php
namespace ERBot\Command;

use Erpk\Harvester\Module\Military\MilitaryModule;
use Erpk\Harvester\Module\Management\ManagementModule;
use Erpk\Common\EntityManager;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class FighterCommand extends Command
{
    protected $stack = array();
    protected $output;
    protected $mil;
    protected $mgmt;

    protected function configure()
    {
        $this
            ->setName('fighter')
            ->setDescription('Burn energy in automatically choosen campaign.')
            ->addOption(
                'delay',
                'd',
                InputOption::VALUE_REQUIRED,
                'Delay fighting by specified amount of seconds',
                0
            );
    }

    protected function addCampaignsToStack()
    {
        foreach (func_get_args() as $array) {
            $this->stack = array_merge($this->stack, $array);
        }
    }

    protected function getCampaignFromStack()
    {
        while ($id = array_shift($this->stack)) {
            $this->writeln('Getting info about campaign '.$id.'... ');
            $campaign = $this->mil->getCampaign($id);
            $this->writeln('Region: '.$campaign->getRegion()->getName());
            $this->writeln('Resistance war: '.($campaign->isResistance() ? 'yes' : 'no'));
            $this->writeln('Getting additional statistics... ');
            $campaignStats = $this->mil->getCampaignStats($campaign);
            if (!$campaignStats['is_finished']) {
                $result = $this->mil->changeWeapon($campaign->getId(), 7);
                if ($result) {
                    return $campaign;
                } else {
                    throw new Exception('You don\'t have weapons');
                }
            } else {
                $this->writeln('Already finished');
            }
        }
        $this->writeln('Stack empty, terminating');
        exit;
    }

    protected function writeln($s)
    {
        $this->output->writeln($s);
    }

    protected function write($s)
    {
        $this->output->write($s);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->getApplication()->erpkClient;
        $this->mil = new MilitaryModule($client);
        $this->mgmt = new ManagementModule($client);
        $this->output = $output;

        $delay = filter_var($input->getOption('delay'), FILTER_VALIDATE_INT);
        if ($delay === false || $delay < 0) {
            throw new RuntimeException('Delay must be specified in seconds.');
        }

        if ($delay > 0) {
            $wakeupAt = time() + $delay;
            $output->writeln('<comment>Waiting until '.gmdate('r', $wakeupAt).'.</comment>');
            time_sleep_until($wakeupAt);
        }

        $this->writeln(str_repeat('-', 30).'DATE:'.date('d/m/Y H:i:s').str_repeat('-', 30));
        
        try {
            $this->output->write('<comment>Loading active battles... </comment>');
            $active = $this->mil->getActiveCampaigns();

            $this->addCampaignsToStack(
                $active['country'],
                $active['cotd'],
                $active['allies']
            );

            $this->writeln('loaded');
            $this->fight($this->getCampaignFromStack());

            // CHECK DAILY ORDER
            $doStatus = $this->mil->getDailyOrderStatus();
            if (isset($doStatus['do_reward_on']) && $doStatus['do_reward_on'] == true) {
                $this->mil->getDailyOrderReward($doStatus['do_mission_id'], $doStatus['groupId']);
            }
        } catch (\Exception $e) {
            $this->writeln('<error>'.(string)$e.'</error>');
        }
    }

    protected function humanSleep()
    {
        $sleep = mt_rand(150, 350)/100;
        //$sleep = mt_rand(150, 250)/100;
        $this->writeln('<comment>Sleeping '.$sleep.' seconds</comment>');
        usleep($sleep*1000000);
    }

    protected function eat()
    {
        $this->writeln('<comment>Eating...</comment>');
        $eatResult = $this->mgmt->eat();
        $this->writeln('<info>health: '.$eatResult['health'].', food remaining: '.$eatResult['food_remaining'].'</info>');
        return $eatResult;
    }

    protected function fight($campaign)
    {
        $this->writeln(str_repeat('-', 60));
        $this->humanSleep();
        
        $this->writeln('<comment>Fighting...</comment>');
        $fightResult = $this->mil->fight($campaign);
        if (isset($fightResult['user']['food_remaining'])) {
            $foodRemaining = $fightResult['user']['food_remaining'];
        } else {
            $foodRemaining = 0;
        }
        $health = isset($fightResult['user']['health']) ? $fightResult['user']['health'] : null;
        $msg = $fightResult['message'];
        $this->writeln('<info>Response: '.$msg.'</info>');
        
        $this->humanSleep();
        
        switch($msg) {
            case 'BATTLE_WON':
                $this->writeln('<comment>Battle won!</comment>');
                $this->fight($this->getCampaignFromStack());
                break;
            case 'ZONE_INACTIVE':
                $this->writeln('<comment>Waiting 5 minutes!</comment>');
                sleep(300);
                $this->fight($campaign);
                break;
            case 'LOW_HEALTH':
                $eatResult = $this->eat();
                if ($eatResult['health'] < 10) {
                    $this->writeln('<comment>Finished!</comment>');
                    return true;
                } elseif ($eatResult['health'] >= 10) {
                    $this->fight($campaign);
                }
                break;
            case 'ENEMY_KILLED':
            case 'ENEMY_ATTACKED':
                if ($msg == 'ENEMY_ATTACKED') {
                    $this->writeln('<info>Attacked, '.$health.' health (enemy has '.$fightResult['enemy']['health'].' health)</info>');
                } else {
                    $this->writeln('<info>Killed, '.$fightResult['user']['givenDamage'].' damage dealt, '.$health.' health</info>');
                    /*$s1 = (int)strtr($fightResult['user']['skill'], array(','=>''));
                    $s2 = (int)strtr($fightResult['enemy']['skill'], array(','=>''));
                    $damage = (60+(($s1-$s2)/10))*(1+($fightResult['user']['weaponDamage']-$fightResult['enemy']['damage'])/400);
                    if (((($health+$foodRemaining)/10)*$damage) < $fightResult['enemy']['health'] && $foodRemaining > 0) {
                        $this->eat();
                        $this->writeln('Done!');
                        exit;
                    }*/
                }

                if ($health >= 30) {
                    $this->fight($campaign);
                } elseif ($health < 30) {
                    if ($foodRemaining > 0) {
                        $eatResult = $this->eat();
                        if ($eatResult['health'] < 30) {
                            $this->writeln('<info>Low energy, finishing!</info>');
                        } elseif ($eatResult['health'] >= 30) {
                            $this->fight($campaign);
                        }
                    } else {
                        $this->writeln('<info>Success, finishing!</info>');
                    }
                }
                break;
            case 'SHOOT_LOCKOUT':
                $this->writeln('<comment>Waiting 30 seconds</comment>');
                sleep(30);
                $this->fight($campaign);
                break;
            default:
                $this->writeln('<error>Unexpected response, terminating</error>');
                return false;
        }
    }
}
