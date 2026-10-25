# Development image: Nextcloud with NextTime mounted from this folder (see docker-compose.yml).
#
# Nextcloud 31 because NextTime needs PHP 8.3 and the official nextcloud:29-apache image ships
# PHP 8.2, which Nextcloud refuses to enable the app on. 31 is also the version the dev server and
# composer's nextcloud/ocp pin use. The base image already has every PHP extension Nextcloud needs
# (gd, zip, intl, bcmath, gmp, imagick, redis, pdo_pgsql, ...). The frontend is built on the host
# (`npm run build`); the app folder is mounted read-only.

FROM nextcloud:31-apache

# Pick up code edits at once (the app is mounted, not copied). Extends the base image's own ini.
RUN { \
    echo 'opcache.revalidate_freq=1'; \
    echo 'max_execution_time=3600'; \
} > /usr/local/etc/php/conf.d/timebank-dev.ini

RUN mkdir -p /var/www/html/custom_apps && \
    chown -R www-data:www-data /var/www/html/custom_apps

EXPOSE 80
