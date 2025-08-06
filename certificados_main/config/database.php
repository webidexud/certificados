<?php
class Database {
    private static $instance = null;
    private $connection;
    
    // Configuración dinámica según entorno
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset = 'utf8mb4';
    private $redis_connection = null;

    private function __construct() {
        // Detectar si estamos en Docker
        if (getenv('DB_HOST')) {
            // === CONFIGURACIÓN DOCKER ===
            $this->host = getenv('DB_HOST');
            $this->db_name = getenv('DB_NAME');
            $this->username = getenv('DB_USER');
            $this->password = getenv('DB_PASSWORD');
            
            // Log para debugging
            error_log("DOCKER DB CONFIG: host={$this->host}, db={$this->db_name}, user={$this->username}");
            
        } else {
            // === CONFIGURACIÓN XAMPP ORIGINAL ===
            $this->host = 'localhost';
            $this->db_name = 'certificados_idexud';
            $this->username = 'root';
            $this->password = '';
        }
        
        $this->connectToDatabase();
        $this->connectToRedis();
    }

    private function connectToDatabase() {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}",
            PDO::ATTR_TIMEOUT            => 30, // 30 segundos timeout
        ];

        $retries = 5;
        $retry_delay = 2; // segundos

        for ($i = 0; $i < $retries; $i++) {
            try {
                $this->connection = new PDO($dsn, $this->username, $this->password, $options);
                error_log("✅ Conexión a base de datos establecida (intento " . ($i + 1) . ")");
                return;
            } catch (PDOException $e) {
                if ($i < $retries - 1) {
                    error_log("⏳ Reintentando conexión a BD en {$retry_delay}s... (intento " . ($i + 1) . "/" . $retries . ")");
                    sleep($retry_delay);
                    $retry_delay *= 2; // Backoff exponencial
                } else {
                    error_log("❌ Error final de conexión a BD: " . $e->getMessage());
                    die("Error de conexión a la base de datos después de {$retries} intentos: " . $e->getMessage());
                }
            }
        }
    }

    private function connectToRedis() {
        // Solo conectar a Redis en Docker
        if (!getenv('REDIS_HOST')) {
            return;
        }

        try {
            if (class_exists('Redis')) {
                $this->redis_connection = new Redis();
                $this->redis_connection->connect(getenv('REDIS_HOST'), 6379, 30);
                
                // Autenticar si hay password
                $redis_password = getenv('REDIS_PASSWORD');
                if ($redis_password) {
                    $this->redis_connection->auth($redis_password);
                }
                
                $this->redis_connection->ping();
                error_log("✅ Conexión a Redis establecida");
            }
        } catch (Exception $e) {
            error_log("⚠️ No se pudo conectar a Redis: " . $e->getMessage());
            $this->redis_connection = null;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        // Verificar si la conexión sigue activa
        try {
            $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            error_log("⚠️ Reconectando a la base de datos...");
            $this->connectToDatabase();
        }
        
        return $this->connection;
    }

    public function getRedis() {
        return $this->redis_connection;
    }

    public function testConnection() {
        try {
            $stmt = $this->connection->query("SELECT 1 as test");
            return $stmt->fetch()['test'] === 1;
        } catch (PDOException $e) {
            error_log("❌ Test de conexión falló: " . $e->getMessage());
            return false;
        }
    }

    public function testRedis() {
        if (!$this->redis_connection) {
            return false;
        }

        try {
            return $this->redis_connection->ping() === '+PONG';
        } catch (Exception $e) {
            error_log("❌ Test de Redis falló: " . $e->getMessage());
            return false;
        }
    }

    // === MÉTODOS PARA CACHE CON REDIS ===
    
    public function cacheGet($key) {
        if (!$this->redis_connection) {
            return null;
        }

        try {
            $value = $this->redis_connection->get($key);
            return $value !== false ? json_decode($value, true) : null;
        } catch (Exception $e) {
            error_log("❌ Error obteniendo cache: " . $e->getMessage());
            return null;
        }
    }

    public function cacheSet($key, $value, $ttl = 3600) {
        if (!$this->redis_connection) {
            return false;
        }

        try {
            return $this->redis_connection->setex($key, $ttl, json_encode($value));
        } catch (Exception $e) {
            error_log("❌ Error guardando cache: " . $e->getMessage());
            return false;
        }
    }

    public function cacheDelete($key) {
        if (!$this->redis_connection) {
            return false;
        }

        try {
            return $this->redis_connection->del($key) > 0;
        } catch (Exception $e) {
            error_log("❌ Error eliminando cache: " . $e->getMessage());
            return false;
        }
    }

    // === MÉTODO PARA HEALTH CHECK ===
    
    public function healthCheck() {
        return [
            'database' => $this->testConnection(),
            'redis' => $this->testRedis(),
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => getenv('ENVIRONMENT') ?: 'xampp'
        ];
    }
}
?>