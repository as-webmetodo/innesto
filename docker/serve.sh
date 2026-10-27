#!/bin/sh
# SPDX-License-Identifier: MPL-2.0
# Serves a fresh, writable copy of the read-only repo at /src as <roots>, the
# way an FTP upload would leave it. INNESTO_DATA_OUTSIDE=0 leaves no writable
# directory outside the webroot (spec §7.7).
set -eu
rm -rf /var/www/html/innesto /var/www/innesto-data-*
mkdir -p /var/www/html/innesto
tar -C /src --exclude=./.git --exclude=./.claude --exclude=./node_modules --exclude=./vendor --exclude=./test-results --exclude=./playwright-report -cf - . \
    | tar -C /var/www/html/innesto -xf -
chown -R www-data:www-data /var/www/html/innesto
if [ "${INNESTO_DATA_OUTSIDE:-1}" = 1 ]; then
    chown www-data:www-data /var/www
fi
exec apache2-foreground
