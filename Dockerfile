FROM php:8.5.12-apache
RUN apt-get update \
    && apt-get install --only-upgrade -y ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html/
EXPOSE 80
