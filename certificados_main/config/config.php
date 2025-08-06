<?php
// Detectar si estamos en Docker
$is_docker = getenv('ENVIRONMENT') === 'docker';

if ($is_docker) {
    // === CONFIGURACIÓN PARA DOCKER ===
    define('BASE_URL', 'http://localhost/');
    define('ADMIN_URL', 'http://localhost/admin/');
    define('PUBLIC_URL', 'http://localhost/');
    
    // URLs de servicios internos
    define('FILE_SERVICE_URL', getenv('FILE_SERVICE_URL') ?: 'http://file-service');
    
    // Rutas de archivos dentro del contenedor
    define('ROOT_PATH', '/var/www/html/');
    define('UPLOAD_PATH', '/var/www/html/uploads/');
    define('TEMPLATE_PATH', '/var/www/html/templates/');
    define('GENERATED_PATH', '/var/www/html/generated/');
    
    // URLs públicas para archivos
    define('UPLOAD_URL', BASE_URL . 'uploads/');
    define('GENERATED_URL', BASE_URL . 'generated/');
    define('TEMPLATE_URL', BASE_URL . 'templates/');
    
} else {
    // === CONFIGURACIÓN ORIGINAL PARA XAMPP ===
    define('BASE_URL', 'http://localhost/certificados_digitales/');
    define('ADMIN_URL', BASE_URL . 'admin/');
    define('PUBLIC_URL', BASE_URL . 'public/');
    
    // Rutas de archivos locales
    define('ROOT_PATH', dirname(__DIR__) . '/');
    define('UPLOAD_PATH', ROOT_PATH . 'uploads/');
    define('TEMPLATE_PATH', ROOT_PATH . 'templates/');
    define('GENERATED_PATH', ROOT_PATH . 'generated/');
    
    // URLs de archivos
    define('UPLOAD_URL', BASE_URL . 'uploads/');
    define('GENERATED_URL', BASE_URL . 'generated/');
    define('TEMPLATE_URL', BASE_URL . 'templates/');
}

// === CONFIGURACIÓN COMÚN ===

// Configuración de archivos
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB para Docker
define('ALLOWED_CSV_EXTENSIONS', ['csv', 'xlsx', 'xls']);
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

// Configuración de errores (mejorada para Docker)
if ($is_docker) {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', '/var/log/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Crear directorios si no existen
$directorios = [
    UPLOAD_PATH, 
    TEMPLATE_PATH, 
    GENERATED_PATH, 
    UPLOAD_PATH . 'plantillas/', 
    UPLOAD_PATH . 'participantes/', 
    GENERATED_PATH . 'certificados/'
];

foreach ($directorios as $directorio) {
    if (!is_dir($directorio)) {
        mkdir($directorio, 0755, true);
    }
}

// === FUNCIONES AUXILIARES PARA DOCKER ===

/**
 * Detecta si estamos ejecutando en Docker
 */
function isDockerEnvironment() {
    return getenv('ENVIRONMENT') === 'docker';
}

/**
 * Obtiene la URL correcta para archivos según el entorno
 */
function getFileUrl($tipo, $archivo) {
    switch ($tipo) {
        case 'upload':
            return UPLOAD_URL . $archivo;
        case 'generated':
            return GENERATED_URL . $archivo;
        case 'template':
            return TEMPLATE_URL . $archivo;
        default:
            return BASE_URL . $archivo;
    }
}

/**
 * Registra eventos para debugging en Docker
 */
function logDockerEvent($message, $context = []) {
    if (isDockerEnvironment()) {
        $log_data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'service' => $_SERVER['HTTP_X_ADMIN_REQUEST'] ?? 'public',
            'message' => $message,
            'context' => $context
        ];
        error_log('DOCKER_EVENT: ' . json_encode($log_data));
    }
}

// Log de inicio
logDockerEvent('Config loaded', [
    'environment' => $is_docker ? 'docker' : 'xampp',
    'base_url' => BASE_URL,
    'upload_path' => UPLOAD_PATH
]);
?>