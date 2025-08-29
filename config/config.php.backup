<?php
// Configuración general del sistema
define('BASE_URL', 'http://localhost/certificados_digitales/');
define('ADMIN_URL', BASE_URL . 'admin/');
define('PUBLIC_URL', BASE_URL . 'public/');

// Rutas de archivos
define('ROOT_PATH', dirname(__DIR__) . '/');
define('UPLOAD_PATH', ROOT_PATH . 'uploads/');
define('TEMPLATE_PATH', ROOT_PATH . 'templates/');
define('GENERATED_PATH', ROOT_PATH . 'generated/');

// URLs de archivos
define('UPLOAD_URL', BASE_URL . 'uploads/');
define('GENERATED_URL', BASE_URL . 'generated/');

// Configuración de archivos
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_CSV_EXTENSIONS', ['csv', 'xlsx']);
define('ALLOWED_IMAGE_EXTENSIONS', ['svg', 'png', 'jpg', 'jpeg']);

// Configuración de certificados
define('CODIGO_LONGITUD', 12);
define('PDF_QUALITY', 300);

// Configuración de paginación
define('REGISTROS_POR_PAGINA', 25);

// Configuración de sesión
define('SESSION_TIMEOUT', 3600); // 1 hora

// Zona horaria
date_default_timezone_set('America/Bogota');

// Configuración de errores (cambiar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Crear directorios si no existen
$directorios = [UPLOAD_PATH, TEMPLATE_PATH, GENERATED_PATH, 
                UPLOAD_PATH . 'plantillas/', UPLOAD_PATH . 'participantes/', 
                GENERATED_PATH . 'certificados/'];

foreach ($directorios as $directorio) {
    if (!is_dir($directorio)) {
        mkdir($directorio, 0755, true);
    }
}
?>