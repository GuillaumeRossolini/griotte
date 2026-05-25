FROM php:8-fpm-trixie@sha256:4528adc6695b76250c0d2290c52b663d1b5c8e7d4df2a7b86af214524f549c5c
# PHP 8.5.6 (built: May 19 2026 23:08:03) (NTS)

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
