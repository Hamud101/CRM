#!/bin/sh
# Both containers run this. The first to start creates the data; the other
# finds it already there. bin/setup.php never overwrites, so a race would just
# find the files present. The lock stops two first runs doing that at once.
set -eu

mkdir -p "$CRM_DATA_DIR"

if [ ! -f "$CRM_DATA_DIR/secrets.php" ]; then
    flock "$CRM_DATA_DIR/.setup.lock" php /var/www/html/bin/setup.php --from-env
fi

# Do this on every start too, so a restore from a backup ends up owned right.
chown -R www-data:www-data "$CRM_DATA_DIR"
chmod 700 "$CRM_DATA_DIR"

exec "$@"
