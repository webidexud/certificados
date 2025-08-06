#!/bin/bash
set -e

echo "🌐 Iniciando Public Service..."

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

# Crear directorio para archivos generados (solo lectura)
echo "📁 Verificando directorios..."
mkdir -p /var/www/html/generated

# Establecer permisos (solo lectura para público)
echo "🔐 Configurando permisos..."
chown -R www-data:www-data /var/www/html/generated
chmod -R 644 /var/www/html/generated

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

echo "🎉 Public Service iniciado correctamente"

# Ejecutar el comando original
exec "$@"