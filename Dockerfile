FROM php:8-cli-alpine@sha256:6ca76906d789edfac74e5f109c800b71e571bd313277133eaddc079733ee0b65
# PHP 8.5.6 (cli) (built: May  8 2026 16:44:06) (NTS)

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
