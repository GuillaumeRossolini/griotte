FROM php:8.3.21-cli-alpine3.20

RUN apk update \
    && apk add sqlite

WORKDIR /var/www

ADD app app
ADD www html

RUN mkdir db run \
    && touch buffer.csv \
    && chmod +x app/csv2sqlite.sh \
    && ln -s /var/www/app/csv2sqlite.sh /usr/local/bin/csv2sqlite

WORKDIR /var/www/html

SHELL ["/bin/sh"]
ENTRYPOINT ["php", "-S", "0.0.0.0:80"]
