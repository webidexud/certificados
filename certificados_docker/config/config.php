<?php
// Configuración general del sistema - DOCKER VERSION
// Detectar entorno Docker
$_ENV['ENVIRONMENT'] = 'docker';

// Detectar si estamos en entorno Docker
$is_docker = isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] === 'docker';

// URLs principales - ACTUALIZADO PARA DOCKER
if ($is_docker) {
    define('BASE_URL', 'http://localhost:9080/');
} else {
    define('BASE_URL', 'http://localhost/certificados_digitales/');
}

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

// Configuración de base de datos - NUEVA BD
if ($is_docker) {
    // Configuración para Docker con nueva BD
    define('DB_HOST', $_ENV['DB_HOST'] ?? 'mysql');
    define('DB_NAME', $_ENV['DB_NAME'] ?? 'certificaciones_ofex_db');
    define('DB_USER', $_ENV['DB_USER'] ?? 'user_certificaiones_ofex');
    define('DB_PASS', $_ENV['DB_PASSWORD'] ?? 'user_password');
} else {
    // Configuración para desarrollo local
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'certificaciones_ofex_db');
    define('DB_USER', 'user_certificaiones_ofex');
    define('DB_PASS', 'user_password');
}

// Configuración de archivos
define('MAX_FILE_SIZE', $_ENV['MAX_FILE_SIZE'] ?? 10 * 1024 * 1024); // 10MB
define('ALLOWED_CSV_EXTENSIONS', ['csv', 'xlsx']);
define('ALLOWED_IMAGE_EXTENSIONS', ['svg', 'png', 'jpg', 'jpeg']);

// Configuración de certificados
define('CODIGO_LONGITUD', 12);
define('PDF_QUALITY', 300);

// Configuración de paginación
define('REGISTROS_POR_PAGINA', 25);

// Configuración de sesión
define('SESSION_TIMEOUT', $_ENV['SESSION_TIMEOUT'] ?? 3600); // 1 hora

// Zona horaria
date_default_timezone_set('America/Bogota');

// Configuración de errores
if ($is_docker && isset($_ENV['DEBUG_MODE']) && $_ENV['DEBUG_MODE'] === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', 0);
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

// Clase Database para conexión
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // SOLUCIÓN DEFINITIVA: Desactivar ONLY_FULL_GROUP_BY
            $this->connection->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            
            // Log de conexión exitosa en Docker
            if (defined('DB_HOST') && DB_HOST === 'mysql') {
                error_log("Conexión exitosa a MySQL Docker: " . DB_NAME . " - SQL_MODE corregido");
            }
            
        } catch (PDOException $e) {
            // Log del error
            error_log("Error de conexión a BD: " . $e->getMessage());
            
            // Mostrar error amigable
            if (isset($_ENV['DEBUG_MODE']) && $_ENV['DEBUG_MODE'] === 'true') {
                die("Error de conexión a la base de datos: " . $e->getMessage());
            } else {
                die("Error de conexión a la base de datos. Contacte al administrador.");
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Método para probar la conexión
    public function testConnection() {
        try {
            $stmt = $this->connection->query("SELECT 1");
            return $stmt !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}

// Función auxiliar para logging
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message" . PHP_EOL;
    
    if (defined('ROOT_PATH')) {
        $logFile = ROOT_PATH . 'logs/app.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    // También enviar a error_log para Docker
    error_log("[$type] $message");
}

// Función para validar configuración
function validarConfiguracion() {
    $errores = [];
    
    // Verificar conexión a BD
    try {
        $db = Database::getInstance();
        if (!$db->testConnection()) {
            $errores[] = "No se puede conectar a la base de datos";
        }
    } catch (Exception $e) {
        $errores[] = "Error de base de datos: " . $e->getMessage();
    }
    
    // Verificar directorios
    $directoriosRequeridos = [UPLOAD_PATH, TEMPLATE_PATH, GENERATED_PATH];
    foreach ($directoriosRequeridos as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            $errores[] = "Directorio no escribible: $dir";
        }
    }
    
    return $errores;
}

// Verificar configuración si estamos en modo debug
if (isset($_ENV['DEBUG_MODE']) && $_ENV['DEBUG_MODE'] === 'true') {
    $errores = validarConfiguracion();
    if (!empty($errores)) {
        foreach ($errores as $error) {
            logMessage($error, 'ERROR');
        }
    }
}
?>