# Script de comandos Docker para Windows
param([string]$command)

switch ($command) {
    "start" {
        Write-Host " Iniciando aplicación..."
        docker-compose up -d
        Write-Host " Aplicación iniciada en http://localhost:8080"
    }
    "stop" {
        Write-Host " Deteniendo aplicación..."
        docker-compose down
        Write-Host " Aplicación detenida"
    }
    "restart" {
        Write-Host " Reiniciando aplicación..."
        docker-compose down
        docker-compose up -d
        Write-Host " Aplicación reiniciada"
    }
    "logs" {
        docker-compose logs -f
    }
    "shell" {
        docker-compose exec app bash
    }
    "backup" {
        Write-Host " Creando backup..."
        $fecha = Get-Date -Format "yyyyMMdd_HHmm"
        if (!(Test-Path "backups")) { mkdir backups }
        docker cp certificados-app:/var/www/html/uploads backups/uploads_$fecha
        docker cp certificados-app:/var/www/html/templates backups/templates_$fecha  
        docker cp certificados-app:/var/www/html/generated backups/generated_$fecha
        Write-Host " Backup completado en carpeta backups/"
    }
    "build" {
        Write-Host " Reconstruyendo imagen..."
        docker-compose build --no-cache
        Write-Host " Imagen reconstruida"
    }
    default {
        Write-Host " Comandos disponibles:"
        Write-Host "  .\docker-commands.ps1 start     - Iniciar aplicación"
        Write-Host "  .\docker-commands.ps1 stop      - Detener aplicación"  
        Write-Host "  .\docker-commands.ps1 restart   - Reiniciar aplicación"
        Write-Host "  .\docker-commands.ps1 logs      - Ver logs en tiempo real"
        Write-Host "  .\docker-commands.ps1 shell     - Acceder al contenedor"
        Write-Host "  .\docker-commands.ps1 backup    - Crear backup de archivos"
        Write-Host "  .\docker-commands.ps1 build     - Reconstruir imagen"
    }
}
