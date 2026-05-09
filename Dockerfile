FROM php:8-cli-alpine@sha256:dccc3abcf3d37a6bb081477a66ed4344716784a6ef5107625ae6ba9ec52df778

RUN apk update \
    && apk add sqlite

WORKDIR /var/www

ADD app app
ADD debug debug
ADD linux/ash/ /root/
ADD www html

RUN mkdir db run \
    && ln -s /var/www/app/csv2sqlite.sh /usr/local/bin/csv2sqlite \
    && ln -s /var/www/debug/dbstats.sh /usr/local/bin/dbstats \
    && find . -type f -name "*.sh" -exec chmod +x {} ';'

WORKDIR /var/www/html

SHELL ["/bin/sh"]
ENTRYPOINT ["php", "-S", "0.0.0.0:80"]
