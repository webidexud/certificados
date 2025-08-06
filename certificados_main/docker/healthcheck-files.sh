#!/bin/bash

# Verificar que Nginx esté corriendo
if ! pgrep nginx > /dev/null; then
    echo "❌ Nginx no está corriendo"
    exit 1
fi

# Verificar que el puerto 80 esté disponible
if ! nc -z localhost 80; then
    echo "❌ Puerto 80 no disponible"
    exit 1
fi

# Verificar que los directorios existan
if [ ! -d "/var/www/html/uploads" ]; then
    echo "❌ Directorio uploads no existe"
    exit 1
fi

if [ ! -d "/var/www/html/generated" ]; then
    echo "❌ Directorio generated no existe"
    exit 1
fi

if [ ! -d "/var/www/html/templates" ]; then
    echo "❌ Directorio templates no existe"
    exit 1
fi

# Verificar espacio en disco (alerta si menos de 1GB libre)
AVAILABLE_SPACE=$(df /var/www/html | tail -1 | awk '{print $4}')
if [ "$AVAILABLE_SPACE" -lt 1048576 ]; then
    echo "⚠️  Advertencia: Poco espacio en disco (menos de 1GB libre)"
fi

echo "✅ File service saludable"
exit 0