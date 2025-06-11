<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

// Función para generar código de verificación único
function generarCodigoVerificacion($longitud = 12) {
    $caracteres = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $codigo = '';
    for ($i = 0; $i < $longitud; $i++) {
        $codigo .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    return $codigo;
}

// Función para verificar si un código ya existe
function codigoExiste($codigo) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM certificados WHERE codigo_verificacion = ?");
    $stmt->execute([$codigo]);
    return $stmt->fetchColumn() > 0;
}


// Función para generar código único
function generarCodigoUnico() {
    do {
        $codigo = generarCodigoVerificacion();
    } while (codigoExiste($codigo));
    return $codigo;
}

// Función para limpiar datos de entrada
function limpiarDatos($data) {
    if ($data === null) {
        return '';
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Función para validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
// Función para formatear fecha (si no existe)
if (!function_exists('formatearFecha')) {
    function formatearFecha($fecha, $formato = 'd/m/Y') {
        return date($formato, strtotime($fecha));
    }
}
// Función para validar fecha
function validarFecha($fecha) {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Función para formatear fecha
function formatearFecha($fecha, $formato = 'd/m/Y') {
    return date($formato, strtotime($fecha));
}

// Función para registrar auditoría
function registrarAuditoria($accion, $tabla, $registro_id = null, $datos_anteriores = null, $datos_nuevos = null) {
    try {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO auditoria (usuario, accion, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $usuario = $_SESSION['usuario'] ?? 'Sistema';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
        
        $stmt->execute([
            $usuario,
            $accion,
            $tabla,
            $registro_id,
            $datos_anteriores ? json_encode($datos_anteriores, JSON_UNESCAPED_UNICODE) : null,
            $datos_nuevos ? json_encode($datos_nuevos, JSON_UNESCAPED_UNICODE) : null,
            $ip,
            $user_agent
        ]);
    } catch (Exception $e) {
        error_log("Error en auditoría: " . $e->getMessage());
    }
}

// Función para mostrar mensajes flash
function mostrarMensaje($tipo, $mensaje) {
    $_SESSION['mensaje'] = [
        'tipo' => $tipo,
        'texto' => $mensaje
    ];
}

function obtenerMensaje() {
    if (isset($_SESSION['mensaje'])) {
        $mensaje = $_SESSION['mensaje'];
        unset($_SESSION['mensaje']);
        return $mensaje;
    }
    return null;
}

// Función para verificar autenticación
function verificarAutenticacion() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        header('Location: ' . ADMIN_URL . 'login.php');
        exit;
    }
    
    // Verificar timeout de sesión
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_destroy();
        header('Location: ' . ADMIN_URL . 'login.php?timeout=1');
        exit;
    }
    
    $_SESSION['last_activity'] = time();
}

// Función para hashear contraseña
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Función para verificar contraseña
function verificarPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Función para subir archivo
function subirArchivo($archivo, $directorio, $tipos_permitidos = []) {
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Error al subir el archivo'];
    }
    
    if ($archivo['size'] > MAX_FILE_SIZE) {
        return ['error' => 'El archivo es demasiado grande'];
    }
    
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!empty($tipos_permitidos) && !in_array($extension, $tipos_permitidos)) {
        return ['error' => 'Tipo de archivo no permitido'];
    }
    
    $nombre_archivo = uniqid() . '_' . time() . '.' . $extension;
    $ruta_destino = $directorio . $nombre_archivo;
    
    if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
        return ['success' => true, 'archivo' => $nombre_archivo, 'ruta' => $ruta_destino];
    }
    
    return ['error' => 'Error al mover el archivo'];
}

// Función para generar slug
function generarSlug($texto) {
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    return trim($texto, '-');
}

// Función de paginación
function paginar($total_registros, $pagina_actual, $registros_por_pagina = REGISTROS_POR_PAGINA) {
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $offset = ($pagina_actual - 1) * $registros_por_pagina;
    
    return [
        'total_registros' => $total_registros,
        'total_paginas' => $total_paginas,
        'pagina_actual' => $pagina_actual,
        'registros_por_pagina' => $registros_por_pagina,
        'offset' => $offset,
        'tiene_anterior' => $pagina_actual > 1,
        'tiene_siguiente' => $pagina_actual < $total_paginas
    ];
}
?>