FROM php:8.2-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensiones PHP necesarias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        mbstring

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Habilitar módulos de Apache
RUN a2enmod rewrite
RUN a2enmod headers
RUN a2enmod ssl

# Configurar PHP para uploads grandes
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Configurar Apache
COPY docker/apache-admin.conf /etc/apache2/sites-available/000-default.conf

# Crear directorios necesarios con permisos correctos
RUN mkdir -p /var/www/html/uploads/plantillas \
    && mkdir -p /var/www/html/uploads/participantes \
    && mkdir -p /var/www/html/generated/certificados \
    && mkdir -p /var/www/html/templates \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Instalar Composer (para futuras dependencias)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiar script de inicio personalizado
COPY docker/entrypoint-admin.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]