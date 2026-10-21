#!/bin/sh
# On shared hosting these are two cron jobs. In Docker this loop is the cron:
# render proposals every minute, and sync every tenth minute. One failed tick
# must not stop the loop, so there is no set -e here.
set -u

tick=0
while true; do
    runuser -u www-data -- php /var/www/html/bin/render-worker.php
    tick=$((tick + 1))
    if [ $((tick % 10)) -eq 0 ]; then
        runuser -u www-data -- php /var/www/html/sync.php
    fi
    sleep 60
done
