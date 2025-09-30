<?php
// admin/api/estadisticas_evento.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

// Configurar headers para API JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Verificar autenticación
//if (!isset($_SESSION['user_id'])) {
//    http_response_code(401);
//    echo json_encode(['error' => 'No autorizado']);
//    exit;
//}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $evento_id = intval($input['evento_id'] ?? 0);
    
    if ($evento_id <= 0) {
        echo json_encode(['error' => 'ID de evento inválido']);
        exit;
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Verificar que el evento existe
    $stmt = $db->prepare("SELECT nombre FROM eventos WHERE id = ?");
    $stmt->execute([$evento_id]);
    $evento = $stmt->fetch();
    
    if (!$evento) {
        echo json_encode(['error' => 'Evento no encontrado']);
        exit;
    }
    
    // Total participantes del evento
    $stmt = $db->prepare("SELECT COUNT(*) FROM participantes WHERE evento_id = ?");
    $stmt->execute([$evento_id]);
    $total_participantes = $stmt->fetchColumn();
    
    // Participantes con certificado
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM certificados c 
        JOIN participantes p ON c.participante_id = p.id 
        WHERE p.evento_id = ?
    ");
    $stmt->execute([$evento_id]);
    $con_certificado = $stmt->fetchColumn();
    
    // Participantes sin certificado
    $sin_certificado = $total_participantes - $con_certificado;
    
    // Porcentaje completado
    $porcentaje_completado = $total_participantes > 0 ? 
        round(($con_certificado / $total_participantes) * 100, 1) : 0;
    
    // Estadísticas por rol
    $stmt = $db->prepare("
        SELECT 
            p.rol,
            COUNT(*) as total,
            COUNT(c.id) as con_certificado,
            (COUNT(*) - COUNT(c.id)) as sin_certificado
        FROM participantes p 
        LEFT JOIN certificados c ON p.id = c.participante_id
        WHERE p.evento_id = ? 
        GROUP BY p.rol 
        ORDER BY total DESC
    ");
    $stmt->execute([$evento_id]);
    $estadisticas_por_rol = $stmt->fetchAll();
    
    // Certificados generados en las últimas 24 horas
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM certificados c 
        JOIN participantes p ON c.participante_id = p.id 
        WHERE p.evento_id = ? 
        AND c.fecha_generacion >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$evento_id]);
    $generados_ultimas_24h = $stmt->fetchColumn();
    
    // Tiempo estimado para completar (basado en 1.5 segundos por certificado)
    $tiempo_estimado_segundos = $sin_certificado * 1.5;
    $tiempo_estimado_minutos = round($tiempo_estimado_segundos / 60, 1);
    
    // Registrar consulta en auditoría
    registrarAuditoria('API_CONSULTA', 'eventos', $evento_id, null, [
        'endpoint' => 'estadisticas_evento',
        'total_participantes' => $total_participantes,
        'con_certificado' => $con_certificado
    ]);
    
    $respuesta = [
        'success' => true,
        'evento_id' => $evento_id,
        'evento_nombre' => $evento['nombre'],
        'total_participantes' => $total_participantes,
        'con_certificado' => $con_certificado,
        'sin_certificado' => $sin_certificado,
        'porcentaje_completado' => $porcentaje_completado,
        'generados_ultimas_24h' => $generados_ultimas_24h,
        'tiempo_estimado' => [
            'segundos' => $tiempo_estimado_segundos,
            'minutos' => $tiempo_estimado_minutos,
            'texto' => $tiempo_estimado_minutos < 1 ? 
                'Menos de 1 minuto' : 
                ($tiempo_estimado_minutos < 60 ? 
                    round($tiempo_estimado_minutos) . ' minutos' : 
                    round($tiempo_estimado_minutos / 60, 1) . ' horas')
        ],
        'por_rol' => $estadisticas_por_rol,
        'resumen' => [
            'estado' => $sin_certificado == 0 ? 'completo' : 'pendiente',
            'urgencia' => $sin_certificado > 100 ? 'alta' : ($sin_certificado > 20 ? 'media' : 'baja'),
            'recomendacion' => $sin_certificado == 0 ? 
                'Todos los certificados han sido generados' :
                ($sin_certificado > 50 ? 
                    'Se recomienda generación masiva' : 
                    'Puede usar generación individual o masiva')
        ]
    ];
    
    echo json_encode($respuesta);
    
} catch (Exception $e) {
    error_log("Error en API estadisticas_evento: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
?>