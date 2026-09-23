FROM php:8.2-apache

# libcurl headers needed to build the curl extension below
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions: mysqli/pdo_mysql for the DB, curl for the
# Groq chat API calls in includes/chat_ai.php
RUN docker-php-ext-configure curl \
    && docker-php-ext-install mysqli pdo pdo_mysql curl

# Enable Apache rewrite module (harmless if unused, needed if you add rewrite rules later)
RUN a2enmod rewrite

# Point Apache's document root at the project root, not /public,
# since /auth/, /customer/, /staff/, etc. must resolve at the top level
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy the project into the container
COPY . /var/www/html/

# profile.php writes uploaded profile pictures here via move_uploaded_file();
# make sure the directory exists and is writable by the Apache user.
# NOTE: Render's filesystem is ephemeral -- anything written here is lost
# on redeploy/restart unless you attach a persistent Disk mounted at this
# path, or switch profile.php to write to external storage (e.g. S3/R2).
RUN mkdir -p /var/www/html/uploads/profile_pictures \
    && chown -R www-data:www-data /var/www/html/uploads

# Render injects PORT at runtime and expects the app to bind to it,
# so rewrite Apache's port config at container start, not build time
RUN printf '#!/bin/sh\nset -e\nPORT="${PORT:-80}"\nsed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-apache.sh \
    && chmod +x /usr/local/bin/start-apache.sh

EXPOSE 80

CMD ["/usr/local/bin/start-apache.sh"]
