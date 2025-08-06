FROM nginx:alpine

# Instalar herramientas útiles
RUN apk add --no-cache \
    curl \
    zip \
    unzip

# Crear directorios para archivos
RUN mkdir -p /var/www/html/uploads/plantillas \
    && mkdir -p /var/www/html/uploads/participantes \
    && mkdir -p /var/www/html/generated/certificados \
    && mkdir -p /var/www/html/templates

# Configurar permisos
RUN chown -R nginx:nginx /var/www/html \
    && chmod -R 755 /var/www/html

# Copiar configuración de Nginx
COPY docker/nginx-files.conf /etc/nginx/nginx.conf

# Copiar script de healthcheck
COPY docker/healthcheck-files.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh

# Configurar healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]