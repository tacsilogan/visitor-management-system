#!/bin/sh
# Starts the web server container (DEPLOYMENT.md): prepares the persistent folders, creates
# or updates the database, prints the readiness report, starts the background jobs, and
# runs Apache in the foreground.
set -eu

export PORT="${PORT:-8080}"
DATA="${ISATU_DATA_DIR:-/data}"
APP=/var/www/html/phone_tracker
PHP=/usr/local/bin/php

# Persistent folders. With a Railway volume mounted at /data they survive every deploy:
# sign-in sessions, staff profile photos, the photos in directions inside buildings, and
# (unless ISATU_AUTH_SECRET is set) the two-step verification key.
mkdir -p "$DATA/sessions" "$DATA/uploads/profile_pictures" "$DATA/uploads/directions" "$DATA/private"
cp -f /usr/local/share/isatu-uploads/profile_pictures/.htaccess "$DATA/uploads/profile_pictures/.htaccess"
cp -f /usr/local/share/isatu-uploads/directions/.htaccess "$DATA/uploads/directions/.htaccess"
chown -R www-data:www-data "$DATA/sessions" "$DATA/uploads" "$DATA/private"
chmod 700 "$DATA/sessions" "$DATA/private"
if [ -z "${ISATU_AUTH_SECRET:-}" ] && [ -z "${ISATU_AUTH_SECRET_FILE:-}" ]; then
    export ISATU_AUTH_SECRET_FILE="$DATA/private/auth_secret.php"
fi

# Everything below runs as the web server's user, never as root.
as_web() {
    runuser -u www-data -- "$@"
}

# Create or update the database tables (waits for the database to start).
if ! as_web "$PHP" "$APP/tools/setup_database.php" --wait --secure-default-accounts; then
    echo "The database is not ready, so the site will report an error until it is. Check the settings above, then redeploy." >&2
fi
as_web "$PHP" "$APP/tools/preflight.php" || true

# Background jobs (emails, push notifications, visit timing, history cleanup), restarted
# if they ever stop. They run in a session of their own: Apache signals everything in its
# own process group when it stops, which would cut their output off mid-line.
setsid sh -c '
    while true; do
        runuser -u www-data -- "$0" "$1/workers/run_scheduled_jobs.php" || true
        sleep 10
    done
' "$PHP" "$APP" &

# Apache must load exactly one MPM, prefork, which mod_php requires. On Railway the
# image's switched-off modules can come back ("More than one MPM loaded", a known Railway
# issue), so they are switched off again at every start. Apache's configuration check
# then runs first, so any remaining problem is printed in the deploy log instead of
# Apache silently restarting.
a2dismod -q -f mpm_event mpm_worker autoindex status > /dev/null 2>&1 || true
a2enmod -q mpm_prefork > /dev/null 2>&1 || true
if ! apache2ctl -t 2> /tmp/apache-configtest.log; then
    echo "Apache configuration check failed:" >&2
    cat /tmp/apache-configtest.log >&2
fi

exec apache2-foreground
