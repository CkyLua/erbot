Introduction
=====
erbot is eRepublik multifeatured bot, which helps you completing daily tasks on your account.

###Building and configuration
```bash
apt-get update
sudo apt-get install git curl php5-cli php5-curl php5-sqlite
wget -O erbot.tar.gz https://github.com/erpk/erbot/archive/master.tar.gz
tar -xzf erbot.tar.gz
mv erbot-master erbot && cd erbot/
curl -sS https://getcomposer.org/installer | php
php composer.phar install
cd bin/ && chmod +x erbot
./erbot configure
```
Usage
=====
In order to display available commands, type command below:
```bash
./erbot list
```

Some of the features
* **`fighter`** - chooses the campaign from the available ones, then burns your all energy available fighting in that battle.  By default it chooses Q7 weapons.
* **`refill`** - refills your energy, useful when you can't visit eRepublik all the time (or in the night)
