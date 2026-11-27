FROM php:8.2-apache

#on active les extensions que mon projet utilise
RUN docker-php-ext-install mysqli pdo pdo_mysql

#On copie tout mon projet dans le serveur
COPY . /var/www/html/

#On donne les droits
RUN chown -R www-data:www-data /var/www/html