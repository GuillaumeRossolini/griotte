FROM php:8.4.14-cli-alpine3.22@sha256:69ee19e1bee51ad7d5b5e44ceaa317cc7904b22211de4fb0c85284ffec123341

RUN apk update \
    && apk add sqlite

WORKDIR /var/www

ADD app app
ADD www html

RUN mkdir db run \
    && chmod +x app/csv2sqlite.sh \
    && ln -s /var/www/app/csv2sqlite.sh /usr/local/bin/csv2sqlite

WORKDIR /var/www/html

SHELL ["/bin/sh"]
ENTRYPOINT ["php", "-S", "0.0.0.0:80"]
