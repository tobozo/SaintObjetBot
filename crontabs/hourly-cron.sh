#!/bin/bash

cd /var/SaintObjetBot

php hourly-cron.php bluesky
php hourly-cron.php mastodon
