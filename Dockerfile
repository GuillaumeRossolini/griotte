FROM php:8-fpm-trixie@sha256:32ef9f35b567a741f24c5d2c3312f803fe6c9e34b7db46212f95fce675e1d13f
# PHP 8.5.7 (built: Jun 11 2026 00:25:28) (NTS)

RUN apt update \
    && apt install -y sqlite3 vim nano

ADD php-fpm/php.ini /usr/local/etc/php/conf.d/griot.ini
ADD php-fpm/fpm-griot.conf /tmp/fpm-griot.conf

RUN cat /usr/local/etc/php-fpm.d/*.conf /tmp/fpm-*.conf > /tmp/php-fpm.conf \
    && find /usr/local/etc/php-fpm.d -type f -delete \
    && cp /tmp/php-fpm.conf /usr/local/etc/php-fpm.d/griot.conf \
    && find /tmp -type f -delete

WORKDIR /var/www

ADD app app
ADD debug debug
ADD linux/bash/ /root/
ADD www html

RUN mkdir db run \
    && chown -R www-data: . \
    && ln -s /var/www/app/csv2sqlite.sh /usr/local/bin/csv2sqlite \
    && ln -s /var/www/debug/dbstats.sh /usr/local/bin/dbstats \
    && find . -type f -name "*.sh" -exec chmod +x {} ';'

WORKDIR /var/www/html
