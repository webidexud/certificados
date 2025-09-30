<?php
// admin/eventos/descargar_plantilla.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';


$plantilla_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$plantilla_id) {
    die('ID de plantilla no válido');
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener información de la plantilla
    $stmt = $db->prepare("
        SELECT p.*, e.nombre as evento_nombre
        FROM plantillas_certificados p
        JOIN eventos e ON p.evento_id = e.id
        WHERE p.id = ?
    ");
    $stmt->execute([$plantilla_id]);
    $plantilla = $stmt->fetch();
    
    if (!$plantilla) {
        die('Plantilla no encontrada');
    }
    
    // Verificar que el archivo existe
    $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
    
    if (!file_exists($ruta_plantilla)) {
        die('Archivo de plantilla no encontrado');
    }
    
    // Registrar descarga en auditoría
    registrarAuditoria('DESCARGA_PLANTILLA', 'plantillas_certificados', $plantilla_id, null, [
        'nombre_plantilla' => $plantilla['nombre_plantilla'],
        'rol' => $plantilla['rol']
    ]);
    
    // Generar nombre de archivo para descarga
    $nombre_evento = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $plantilla['evento_nombre']);
    $nombre_rol = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $plantilla['rol']);
    $nombre_descarga = "Plantilla_{$nombre_evento}_{$nombre_rol}.svg";
    
    // Configurar headers para descarga
    header('Content-Type: image/svg+xml');
    header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
    header('Content-Length: ' . filesize($ruta_plantilla));
    header('Cache-Control: private');
    header('Pragma: private');
    
    // Enviar archivo
    readfile($ruta_plantilla);
    exit;
    
} catch (Exception $e) {
    die('Error al descargar la plantilla: ' . $e->getMessage());
}
?>