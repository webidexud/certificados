#!/bin/bash

# ==============================================
# COMANDOS ÚTILES PARA CERTIFICADOS DOCKER
# ==============================================

case "$1" in
    "start")
        echo "🚀 Iniciando todos los servicios..."
        docker-compose up -d
        echo "✅ Servicios iniciados"
        echo "📱 Admin: http://localhost/admin"
        echo "🌐 Público: http://localhost"
        echo "📊 Portainer: http://localhost:9000"
        ;;
    
    "stop")
        echo "🛑 Deteniendo todos los servicios..."
        docker-compose down
        echo "✅ Servicios detenidos"
        ;;
    
    "restart")
        echo "🔄 Reiniciando servicios..."
        docker-compose down
        docker-compose up -d
        echo "✅ Servicios reiniciados"
        ;;
    
    "logs")
        if [ "$2" ]; then
            echo "📋 Mostrando logs de $2..."
            docker-compose logs -f "$2"
        else
            echo "📋 Mostrando logs de todos los servicios..."
            docker-compose logs -f
        fi
        ;;
    
    "build")
        if [ "$2" ]; then
            echo "🔨 Reconstruyendo $2..."
            docker-compose build "$2"
            docker-compose up -d "$2"
        else
            echo "🔨 Reconstruyendo todos los servicios..."
            docker-compose build
            docker-compose up -d
        fi
        ;;
    
    "shell")
        if [ "$2" ]; then
            echo "🐚 Accediendo a shell de $2..."
            docker exec -it "certificates-$2" bash
        else
            echo "❌ Especifica el servicio: admin, public, mysql, redis"
        fi
        ;;
    
    "status")
        echo "📊 Estado de los servicios:"
        docker-compose ps
        echo ""
        echo "🔍 Health checks:"
        curl -s http://localhost/health && echo ""
        curl -s http://localhost:3005/health && echo ""
        ;;
    
    "db")
        echo "🗄️ Accediendo a MySQL..."
        docker exec -it certificates-mysql mysql -u certificates_user -p certificados_idexud
        ;;
    
    "redis")
        echo "📮 Accediendo a Redis..."
        docker exec -it certificates-redis redis-cli -a redis123
        ;;
    
    "clean")
        echo "🧹 Limpiando contenedores e imágenes no utilizadas..."
        docker system prune -f
        echo "✅ Limpieza completada"
        ;;
    
    "reset")
        echo "⚠️ ADVERTENCIA: Esto eliminará TODOS los datos!"
        read -p "¿Estás seguro? (y/N): " confirm
        if [[ $confirm == "y" || $confirm == "Y" ]]; then
            echo "🗑️ Eliminando todo..."
            docker-compose down -v
            docker system prune -af
            echo "✅ Reset completado"
        else
            echo "❌ Operación cancelada"
        fi
        ;;
    
    "backup")
        echo "💾 Creando backup de la base de datos..."
        mkdir -p backups
        docker exec certificates-mysql mysqldump -u certificates_user -pcertificates123 certificados_idexud > "backups/backup_$(date +%Y%m%d_%H%M%S).sql"
        echo "✅ Backup creado en carpeta backups/"
        ;;
    
    "restore")
        if [ "$2" ]; then
            echo "📥 Restaurando backup $2..."
            docker exec -i certificates-mysql mysql -u certificates_user -pcertificates123 certificados_idexud < "$2"
            echo "✅ Backup restaurado"
        else
            echo "❌ Especifica el archivo de backup"
        fi
        ;;
    
    "setup")
        echo "⚙️ Configuración inicial del proyecto..."
        
        # Crear directorios necesarios
        mkdir -p docker
        mkdir -p backups
        mkdir -p logs
        
        # Permisos para scripts
        chmod +x docker/entrypoint-*.sh
        chmod +x docker/healthcheck-*.sh
        
        # Crear archivo de hosts local
        echo "127.0.0.1 certificates.local" >> /etc/hosts 2>/dev/null || echo "⚠️ No se pudo agregar al archivo hosts (requiere sudo)"
        echo "127.0.0.1 admin.certificates.local" >> /etc/hosts 2>/dev/null
        echo "127.0.0.1 public.certificates.local" >> /etc/hosts 2>/dev/null
        
        echo "✅ Configuración inicial completada"
        ;;
    
    "info")
        echo "ℹ️ Información del sistema:"
        echo "=========================================="
        echo "🐳 Docker version: $(docker --version)"
        echo "🐙 Docker Compose version: $(docker-compose --version)"
        echo ""
        echo "📊 Estado de contenedores:"
        docker-compose ps
        echo ""
        echo "💾 Uso de volúmenes:"
        docker volume ls | grep certificates
        echo ""
        echo "🌐 URLs disponibles:"
        echo "  - Admin Panel: http://localhost/admin"
        echo "  - Público: http://localhost"
        echo "  - Admin directo: http://localhost:3001"
        echo "  - Público directo: http://localhost:3002"
        echo "  - File Service: http://localhost:3005"
        echo "  - Portainer: http://localhost:9000"
        ;;
    
    *)
        echo "📚 Comandos disponibles:"
        echo "================================"
        echo "  start     - Iniciar servicios"
        echo "  stop      - Detener servicios"
        echo "  restart   - Reiniciar servicios"
        echo "  logs      - Ver logs [servicio]"
        echo "  build     - Reconstruir [servicio]"
        echo "  shell     - Acceder a shell [servicio]"
        echo "  status    - Estado de servicios"
        echo "  db        - Acceder a MySQL"
        echo "  redis     - Acceder a Redis"
        echo "  clean     - Limpiar Docker"
        echo "  reset     - Reset completo (¡ELIMINA DATOS!)"
        echo "  backup    - Backup de BD"
        echo "  restore   - Restaurar backup [archivo]"
        echo "  setup     - Configuración inicial"
        echo "  info      - Información del sistema"
        echo ""
        echo "📝 Ejemplos:"
        echo "  ./docker-commands.sh start"
        echo "  ./docker-commands.sh logs admin"
        echo "  ./docker-commands.sh shell admin"
        echo "  ./docker-commands.sh build public"
        ;;
esac