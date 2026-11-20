FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libcurl4-openssl-dev cron nano tzdata \
 && docker-php-ext-install pdo_pgsql pgsql curl \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/* \
 && echo 'date.timezone = ${TZ}' > /usr/local/etc/php/conf.d/timezone.ini
COPY crontab /etc/cron.d/forecast
RUN chmod 0644 /etc/cron.d/forecast
ENV TERM=xterm
