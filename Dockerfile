FROM php:8-fpm-trixie@sha256:4528adc6695b76250c0d2290c52b663d1b5c8e7d4df2a7b86af214524f549c5c
# PHP 8.5.6 (built: May 19 2026 23:08:03) (NTS)

ADD php-fpm/php.ini /usr/local/etc/php/conf.d/griot.ini
ADD php-fpm/fpm-griot.conf /tmp/fpm-griot.conf
RUN cat /usr/local/etc/php-fpm.d/*.conf /tmp/fpm-*.conf > /tmp/php-fpm.conf

RUN find /usr/local/etc/php-fpm.d -type f -delete \
    && cp /tmp/php-fpm.conf /usr/local/etc/php-fpm.d/griot.conf \
    && find /tmp -type f -name "*.conf" -delete

RUN apt update \
    && apt install -y sqlite3

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
