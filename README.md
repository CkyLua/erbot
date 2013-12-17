erbot
=====

eRepublik multifeatured bot

####Building
```bash
wget -O erbot.tar.gz https://github.com/erpk/erbot/archive/master.tar.gz
tar xzf erbot.tar.gz
cd erbot-master/
curl -sS https://getcomposer.org/installer | php
php composer.phar install
chmod +x bin/erbot
```
####Configuration
```bash
./bin/erbot configure
```
####Usage
Check available commands:
```bash
./bin/erbot list
```

Display the help on command (command `fighter` here):
```bash
./bin/erbot help fighter
```
