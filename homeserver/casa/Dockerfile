# Imagem única do sistema da casa: Apache + PHP 8.4 (web/API) e o mesmo PHP para o worker.
FROM php:8.4-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql sockets pcntl \
 && apt-get purge -y --auto-remove libpq-dev \
 && apt-get install -y --no-install-recommends libpq5 \
 && rm -rf /var/lib/apt/lists/*

ENV APACHE_PORT=8080
COPY docker/apache-casa.conf /etc/apache2/sites-available/000-default.conf
# zz- para carregar depois do security.conf do Debian (senão ServerTokens volta a "OS").
RUN sed -i 's/^Listen 80$/Listen ${APACHE_PORT}/' /etc/apache2/ports.conf \
 && a2enmod headers >/dev/null \
 && { echo 'ServerName homeserver'; echo 'ServerTokens Prod'; echo 'ServerSignature Off'; echo 'TraceEnable Off'; } > /etc/apache2/conf-enabled/zz-casa.conf \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { echo 'date.timezone=America/Sao_Paulo'; echo 'expose_php=Off'; echo 'max_execution_time=90'; \
      echo 'session.cookie_httponly=1'; echo 'session.use_strict_mode=1'; echo 'session.cookie_samesite=Lax'; } > "$PHP_INI_DIR/conf.d/casa.ini"

WORKDIR /app
