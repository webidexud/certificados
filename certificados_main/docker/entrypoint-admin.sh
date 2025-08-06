#!/bin/bash
set -e

echo "🚀 Iniciando Admin Service..."

# Esperar a que MySQL esté disponible
echo "⏳ Esperando MySQL..."
while ! nc -z mysql 3306; do
  sleep 1
done
echo "✅ MySQL está disponible"

# Esperar a que Redis esté disponible
echo "⏳ Esperando Redis..."
while ! nc -z redis 6379; do
  sleep 1
done
echo "✅ Redis está disponible"

# Crear directorios necesarios si no existen
echo "📁 Verificando directorios..."
mkdir -p /var/www/html/uploads/plantillas
mkdir -p /var/www/html/uploads/participantes
mkdir -p /var/www/html/generated/certificados
mkdir -p /var/www/html/templates

# Establecer permisos correctos
echo "🔐 Configurando permisos..."
chown -R www-data:www-data /var/www/html/uploads
chown -R www-data:www-data /var/www/html/generated
chown -R www-data:www-data /var/www/html/templates
chmod -R 755 /var/www/html/uploads
chmod -R 755 /var/www/html/generated
chmod -R 755 /var/www/html/templates

# Verificar conexión a base de datos
echo "🔍 Verificando conexión a base de datos..."
php -r "
try {
    \$pdo = new PDO('mysql:host=mysql;dbname=certificados_idexud', 'certificates_user', '$DB_PASSWORD');
    echo '✅ Conexión a base de datos exitosa\n';
} catch (Exception \$e) {
    echo '❌ Error de conexión: ' . \$e->getMessage() . '\n';
    exit(1);
}
"

# Verificar conexión a Redis
echo "🔍 Verificando conexión a Redis..."
php -r "
try {
    \$redis = new Redis();
    \$redis->connect('redis', 6379);
    if ('$REDIS_PASSWORD') {
        \$redis->auth('$REDIS_PASSWORD');
    }
    \$redis->ping();
    echo '✅ Conexión a Redis exitosa\n';
} catch (Exception \$e) {
    echo '❌ Error de conexión a Redis: ' . \$e->getMessage() . '\n';
    exit(1);
}
"

echo "🎉 Admin Service iniciado correctamente"

# Ejecutar el comando original
exec "$@"