# Development image: Nextcloud with NextTime mounted from this folder (see docker-compose.yml).
#
# Nextcloud 35, the version production and development run. NextTime needs PHP 8.3 or newer and
# the official nextcloud:29-apache image ships PHP 8.2, which Nextcloud refuses to enable the app
# on. Pinned to a major version on purpose: a newer major is a deliberate upgrade (one at a time),
# never something a rebuild does. The base image already has every PHP extension Nextcloud needs
# (gd, zip, intl, bcmath, gmp, imagick, redis, pdo_pgsql, ...). The frontend is built on the host
# (`npm run build`); the app folder is mounted read-only.

FROM nextcloud:35-apache

# Pick up code edits at once (the app is mounted, not copied). Extends the base image's own ini.
RUN { \
    echo 'opcache.revalidate_freq=1'; \
    echo 'max_execution_time=3600'; \
} > /usr/local/etc/php/conf.d/timebank-dev.ini

RUN mkdir -p /var/www/html/custom_apps && \
    chown -R www-data:www-data /var/www/html/custom_apps

EXPOSE 80
