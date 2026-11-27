FROM php:8.5.13-apache-trixie@sha256:fcf1877806085ea8ceec1072f93c1fed4df701f5da21ff5c976c3c362e4b7859
RUN apt-get update \
    && apt-get install --only-upgrade -y ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html/
EXPOSE 80
