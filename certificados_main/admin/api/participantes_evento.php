<?php
// admin/api/participantes_evento.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

// Configurar headers para API JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

try {
    $evento_id = intval($_GET['evento_id'] ?? 0);
    
    if ($evento_id <= 0) {
        echo json_encode(['error' => 'ID de evento inválido']);
        exit;
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Obtener participantes sin certificado para el evento
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.nombres,
            p.apellidos,
            p.numero_identificacion,
            p.correo_electronico,
            p.rol,
            p.telefono,
            p.institucion,
            e.nombre as evento_nombre
        FROM participantes p
        JOIN eventos e ON p.evento_id = e.id
        LEFT JOIN certificados c ON p.id = c.participante_id
        WHERE p.evento_id = ? AND c.id IS NULL
        ORDER BY p.nombres, p.apellidos
    ");
    
    $stmt->execute([$evento_id]);
    $participantes = $stmt->fetchAll();
    
    // Registrar consulta en auditoría
    registrarAuditoria('API_CONSULTA', 'participantes', null, null, [
        'endpoint' => 'participantes_evento',
        'evento_id' => $evento_id,
        'resultados' => count($participantes)
    ]);
    
    echo json_encode([
        'success' => true,
        'evento_id' => $evento_id,
        'total' => count($participantes),
        'participantes' => $participantes
    ]);
    
} catch (Exception $e) {
    error_log("Error en API participantes_evento: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>