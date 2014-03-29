<?php
namespace ERBot\Command;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\Event;

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

class FighterCommand extends Command implements EventSubscriberInterface
{
    protected $campaignsAvailable = array();
    protected $weaponQuality = 7;
    protected $minimumEnergy = 40;

    public static function getSubscribedEvents()
    {
        return [
            'startup'             => ['onStartup'],
            'exit'                => ['onExit'],
            'fight.ready'         => ['onFightReady'],
            'fight.BATTLE_WON'    => ['onBattleWon'],
            'fight.ZONE_INACTIVE' => ['onZoneInactive'],
            'fight.LOW_HEALTH'    => ['onLowEnergy'],
            'fight.ENEMY_KILLED'  => ['onEnemyKilled'],
            'fight.ENEMY_ATTACKED'=> ['onEnemyKilled'],
            'fight.SHOOT_LOCKOUT' => ['onShootLockout']
        ];
    }

    protected function configure()
    {
        $this
            ->setName('fighter')
            ->setDescription('Burn energy in automatically choosen campaign.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this);

        $event = new Event();
        $event->output = $output;
        $event->client = $this->getApplication()->erpkClient;
        $event->mgmt   = new ManagementModule($event->client);
        $event->mil    = new MilitaryModule($event->client);

        $dispatcher->dispatch('startup', $event);

        // CHECK DAILY ORDER
        $event->output->write('<comment>Checking Daily Order status... </comment>');
        $doStatus = $event->mil->getDailyOrderStatus();
        if (isset($doStatus['do_reward_on']) && $doStatus['do_reward_on'] == true) {
            $event->mil->getDailyOrderReward($doStatus['do_mission_id'], $doStatus['groupId']);

        }
        $event->output->writeln('<comment>done</comment>');
    }

    protected function addCampaigns($campaigns)
    {
        $this->campaignsAvailable = array_merge($this->campaignsAvailable, $campaigns);
    }

    protected function chooseCampaign($event)
    {
        while ($id = array_shift($this->campaignsAvailable)) {
            $event->output->writeln('Getting info about campaign '.$id.'... ');
            $campaign = $event->mil->getCampaign($id);
            $event->output->writeln('Region: '.$campaign->getRegion()->getName());
            $event->output->writeln('Resistance war: '.($campaign->isResistance() ? 'yes' : 'no'));
            $event->output->writeln('Getting additional statistics... ');
            $campaignStats = $event->mil->getCampaignStats($campaign);
            if (!$campaignStats['is_finished']) {
                $result = $event->mil->changeWeapon($campaign->getId(), $this->weaponQuality);

                if ($result) {
                    $event->output->writeln('Using Q'.$this->weaponQuality.' weapons.');
                } else {
                    $event->output->writeln('Using no weapons to fight.');
                    $result = $event->mil->changeWeapon($campaign->getId(), 0);
                }

                return $campaign;
            } else {
                $event->output->writeln('Already ended');
            }
        }
        $event->output->writeln('No campaigns available, terminating.');
        exit;
    }

    public function onStartup(Event $event, $eventName, $dispatcher)
    {
        $event->output->writeln(
            str_repeat('-', 30).'DATE: '.date('r').str_repeat('-', 30)
        );
        
        $event->output->write('<comment>Loading active campaigns... </comment>');
        $active = $event->mil->getActiveCampaigns();

        $this->addCampaigns($active['country']);
        $this->addCampaigns($active['cotd']);
        $this->addCampaigns($active['allies']);

        $event->output->writeln('loaded');
        $event->currentCampaign = $this->chooseCampaign($event);

        while (true) {
            $dispatcher->dispatch('fight.ready', $event);
            $this->humanSleep($event);
        }
    }

    public function onExit(Event $event, $eventName, $dispatcher)
    {
        $event->output->write('<comment>Done.</comment>');
        exit;
    }

    public function onFightReady(Event $event, $eventName, $dispatcher)
    {
        $event->output->writeln(str_repeat('-', 60));
        $event->output->writeln('<comment>Fighting... </comment>');
        $event->fightResult = $event->mil->fight($event->currentCampaign);

        $event->hp =
            isset($event->fightResult['user']['health']) ?
            $event->fightResult['user']['health'] : null;
        $event->hpRecoverable =
            isset($event->fightResult['user']['food_remaining']) ?
            $event->fightResult['user']['food_remaining'] : 0;

        $status = $event->fightResult['message'];
        $event->output->writeln('<info>Response: '.$status.'</info>');
        $dispatcher->dispatch('fight.'.$status, $event);
    }

    public function onBattleWon(Event $event, $eventName, $dispatcher)
    {
        $event->output->writeln('<comment>Campaign ended.</comment>');
        $event->currentCampaign = $this->chooseCampaign($event);
    }

    public function onZoneInactive(Event $event, $eventName, $dispatcher)
    {
        $event->output->writeln('<comment>Waiting for the next round!</comment>');
        sleep(30);
    }

    public function onLowEnergy(Event $event, $eventName, $dispatcher)
    {
        $this->eat($event);

        if ($event->hp < $this->minimumEnergy) {
            $event->output->writeln('<comment>Not enough energy to fight, terminating!</comment>');
            $dispatcher->dispatch('exit', $event);
        }
    }

    public function onEnemyKilled(Event $event, $eventName, $dispatcher)
    {
        $dmgDealt =
            isset($event->fightResult['user']['givenDamage']) ?
            $event->fightResult['user']['givenDamage'] : 0;

        $event->output->writeln(
            '<info>Dealt '.$dmgDealt.' damage, '.
            'you have '.round($event->hp).' HP and '.round($event->hpRecoverable).' HP recoverable</info>'
        );

        if ($event->hp < $this->minimumEnergy) {
            if ($event->hpRecoverable > 0) {
                $this->eat($event);
                if ($event->hp < $this->minimumEnergy) {
                    $event->output->writeln('<info>Not enough energy to fight, terminating!</info>');
                }
            } else {
                $event->output->writeln('<info>Success, finishing!</info>');
                $dispatcher->dispatch('exit', $event);
            }
        }
    }

    public function onShootLockout(Event $event, $eventName, $dispatcher)
    {
        $event->output->writeln('<comment>Too fast, waiting a moment...</comment>');
        sleep(30);
    }

    protected function humanSleep($event)
    {
        $sleep = mt_rand(150, 350)/100; // <1.5s, 3.5s>
        $event->output->writeln('<comment>Waiting '.$sleep.' seconds</comment>');
        usleep($sleep*1000000);
    }

    protected function eat(Event $event)
    {
        $event->output->write('<comment>Eating... </comment>');
        
        $attempts = 5;
        while ($event->hpRecoverable > 0 && $attempts > 0) {
            $eatResult = $event->mgmt->eat();
            $event->hp = $eatResult['health'];
            $event->hpRecoverable = $eatResult['food_remaining'];
            $attempts--;
        }

        $event->output->writeln(
            '<comment>now you have '.$event->hp.' HP and '.$event->hpRecoverable.' HP recoverable<comment>'
        );
    }
}
