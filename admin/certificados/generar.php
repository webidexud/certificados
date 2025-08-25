<?php
// admin/certificados/generar.php - VERSIÓN CON DISEÑO COHERENTE
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$error = '';
$success = '';
$eventos = [];
$participantes = [];
$evento_seleccionado = null;

// Mostrar mensajes de sesión
if (isset($_SESSION['success_mensaje'])) {
    $success = $_SESSION['success_mensaje'];
    unset($_SESSION['success_mensaje']);
}
if (isset($_SESSION['error_mensaje'])) {
    $error = $_SESSION['error_mensaje'];
    unset($_SESSION['error_mensaje']);
}

// Obtener lista de eventos
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM eventos WHERE estado = 'activo' ORDER BY fecha_inicio DESC");
    $stmt->execute();
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

// Obtener evento seleccionado
$evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : 0;

if ($evento_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
        $stmt->execute([$evento_id]);
        $evento_seleccionado = $stmt->fetch();
        
        if ($evento_seleccionado) {
            // Obtener participantes con información de certificados
            $stmt = $db->prepare("
                SELECT p.*, 
                       c.id as certificado_id,
                       c.codigo_verificacion,
                       c.fecha_generacion,
                       COUNT(pt.id) as plantillas_disponibles
                FROM participantes p
                LEFT JOIN certificados c ON p.id = c.participante_id
                LEFT JOIN plantillas_certificados pt ON p.evento_id = pt.evento_id 
                    AND (pt.rol = p.rol OR pt.rol = 'General')
                WHERE p.evento_id = ?
                GROUP BY p.id
                ORDER BY p.apellidos, p.nombres
            ");
            $stmt->execute([$evento_id]);
            $participantes = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = "Error al cargar datos del evento: " . $e->getMessage();
    }
}

// PROCESAR ACCIONES MASIVAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $participantes_seleccionados = $_POST['participantes'] ?? [];
    $evento_id_post = intval($_POST['evento_id'] ?? 0);
    
    error_log("DEBUG: Acción recibida: " . $accion);
    error_log("DEBUG: Participantes seleccionados: " . print_r($participantes_seleccionados, true));
    error_log("DEBUG: Evento ID: " . $evento_id_post);
    
    if (empty($participantes_seleccionados)) {
        $_SESSION['error_mensaje'] = 'Debe seleccionar al menos un participante';
    } else {
        try {
            if ($accion === 'generar_certificados') {
                $generados = 0;
                $errores = 0;
                $mensajes_error = [];
                
                foreach ($participantes_seleccionados as $participante_id) {
                    $participante_id = intval($participante_id);
                    
                    try {
                        // Verificar que no tenga certificado ya
                        $stmt = $db->prepare("SELECT COUNT(*) FROM certificados WHERE participante_id = ?");
                        $stmt->execute([$participante_id]);
                        if ($stmt->fetchColumn() > 0) {
                            continue; // Ya tiene certificado, saltar
                        }
                        
                        // Obtener datos del participante
                        $stmt = $db->prepare("
                            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion, e.descripcion
                            FROM participantes p 
                            JOIN eventos e ON p.evento_id = e.id 
                            WHERE p.id = ?
                        ");
                        $stmt->execute([$participante_id]);
                        $participante = $stmt->fetch();
                        
                        if (!$participante) {
                            $errores++;
                            $mensajes_error[] = "Participante ID $participante_id no encontrado";
                            continue;
                        }
                        
                        // Buscar plantilla para el rol
                        $stmt = $db->prepare("
                            SELECT * FROM plantillas_certificados 
                            WHERE evento_id = ? AND (rol = ? OR rol = 'General') 
                            ORDER BY CASE WHEN rol = ? THEN 1 ELSE 2 END 
                            LIMIT 1
                        ");
                        $stmt->execute([$participante['evento_id'], $participante['rol'], $participante['rol']]);
                        $plantilla = $stmt->fetch();
                        
                        if (!$plantilla) {
                            $errores++;
                            $mensajes_error[] = "Sin plantilla para rol: " . $participante['rol'];
                            continue;
                        }
                        
                        // Generar certificado usando la función completa
                        $resultado = generarCertificadoMasivo($participante, $plantilla);
                        
                        if ($resultado['success']) {
                            $generados++;
                            error_log("DEBUG: Certificado generado para participante $participante_id");
                        } else {
                            $errores++;
                            $mensajes_error[] = $resultado['error'];
                        }
                        
                    } catch (Exception $e) {
                        $errores++;
                        $mensajes_error[] = "Error con participante $participante_id: " . $e->getMessage();
                        error_log("DEBUG: Error generando certificado: " . $e->getMessage());
                    }
                }
                
                // Mensaje de resultado
                if ($generados > 0) {
                    $_SESSION['success_mensaje'] = "✅ Se generaron $generados certificados exitosamente";
                    if ($errores > 0) {
                        $_SESSION['success_mensaje'] .= ". Hubo $errores errores: " . implode('; ', array_slice($mensajes_error, 0, 3));
                    }
                } else {
                    $_SESSION['error_mensaje'] = "❌ No se generaron certificados. Errores: " . implode('; ', $mensajes_error);
                }
                
            } elseif ($accion === 'eliminar_certificados') {
                $eliminados = 0;
                
                foreach ($participantes_seleccionados as $participante_id) {
                    $participante_id = intval($participante_id);
                    
                    try {
                        // Obtener certificado
                        $stmt = $db->prepare("SELECT * FROM certificados WHERE participante_id = ?");
                        $stmt->execute([$participante_id]);
                        $certificado = $stmt->fetch();
                        
                        if ($certificado) {
                            // Eliminar archivo físico
                            $ruta_archivo = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
                            if (file_exists($ruta_archivo)) {
                                unlink($ruta_archivo);
                            }
                            
                            // Eliminar de BD
                            $stmt = $db->prepare("DELETE FROM certificados WHERE participante_id = ?");
                            if ($stmt->execute([$participante_id])) {
                                $eliminados++;
                                error_log("DEBUG: Certificado eliminado para participante $participante_id");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("DEBUG: Error eliminando certificado: " . $e->getMessage());
                    }
                }
                
                if ($eliminados > 0) {
                    $_SESSION['success_mensaje'] = "✅ Se eliminaron $eliminados certificados";
                } else {
                    $_SESSION['error_mensaje'] = "❌ No se eliminaron certificados";
                }
            }
            
        } catch (Exception $e) {
            $_SESSION['error_mensaje'] = 'Error del sistema: ' . $e->getMessage();
            error_log("DEBUG: Error general: " . $e->getMessage());
        }
    }
    
    // Redirigir para limpiar POST
    header("Location: generar.php?evento_id=" . $evento_id_post);
    exit;
}

// FUNCIÓN PARA GENERAR CERTIFICADO MASIVO (COMPLETA)
function generarCertificadoMasivo($participante, $plantilla) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Leer plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        $contenido_svg = file_get_contents($ruta_plantilla);
        
        if ($contenido_svg === false) {
            throw new Exception("No se pudo leer la plantilla SVG");
        }
        
        // Datos para reemplazar
        $datos_certificado = [
            '{{nombres}}' => $participante['nombres'],
            '{{apellidos}}' => $participante['apellidos'],
            '{{numero_identificacion}}' => $participante['numero_identificacion'],
            '{{correo_electronico}}' => $participante['correo_electronico'],
            '{{rol}}' => $participante['rol'],
            '{{telefono}}' => $participante['telefono'] ?: '',
            '{{institucion}}' => $participante['institucion'] ?: '',
            '{{evento_nombre}}' => $participante['evento_nombre'],
            '{{fecha_inicio}}' => date('d/m/Y', strtotime($participante['fecha_inicio'])),
            '{{fecha_fin}}' => date('d/m/Y', strtotime($participante['fecha_fin'])),
            '{{entidad_organizadora}}' => $participante['entidad_organizadora'],
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => $participante['lugar'] ?: 'Virtual',
            '{{horas_duracion}}' => $participante['horas_duracion'],
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{nombre_completo}}' => $participante['nombres'] . ' ' . $participante['apellidos'],
            '{{url_verificacion}}' => BASE_URL . 'public/verificar.php?codigo=' . $codigo_verificacion
        ];
        
        // Reemplazar variables
        $contenido_final = str_replace(array_keys($datos_certificado), array_values($datos_certificado), $contenido_svg);
        
        // Generar archivo
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Crear directorio si no existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $contenido_final) === false) {
            throw new Exception("No se pudo escribir el archivo SVG");
        }
        
        // Hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $codigo_verificacion . $participante['nombres']);
        
        // Insertar en BD
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo) 
            VALUES (?, ?, ?, ?, ?, 'svg')
        ");
        
        if ($stmt->execute([$participante['id'], $participante['evento_id'], $codigo_verificacion, $nombre_archivo, $hash_validacion])) {
            // Registrar auditoría
            registrarAuditoria('GENERAR_CERTIFICADO', 'certificados', $db->lastInsertId(), null, [
                'participante_id' => $participante['id'],
                'codigo_verificacion' => $codigo_verificacion,
                'archivo' => $nombre_archivo
            ]);
            
            return ['success' => true, 'codigo' => $codigo_verificacion, 'archivo' => $nombre_archivo];
        } else {
            throw new Exception("Error al insertar en la base de datos");
        }
        
    } catch (Exception $e) {
        error_log("Error en generarCertificadoMasivo: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados - Sistema de Certificados</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            line-height: 1.6;
            color: #333;
        }
        
        /* HEADER */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo h1 {
            font-size: 1.6rem;
            font-weight: 700;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
        }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.6rem 1.2rem;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }
        
        /* NAVIGATION */
        .nav {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 73px;
            z-index: 999;
        }
        
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
        }
        
        .nav a {
            display: block;
            padding: 1rem 0;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            position: relative;
        }
        
        .nav a:hover, .nav a.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        /* CONTAINER */
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 20px;
        }
        
        /* PAGE HEADER */
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title h2 {
            color: #333;
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .breadcrumb {
            color: #666;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* CARDS */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
            font-size: 1.1rem;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        /* ALERTS */
        .alert {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 10px;
            font-weight: 500;
            border-left: 4px solid;
        }
        
        .alert-success {
            background-color: #d4f6d4;
            color: #0f5132;
            border-left-color: #28a745;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }
        
        /* FORM ELEMENTS */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
            font-size: 0.95rem;
        }
        
        select, input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* BUTTONS - Más Minimalistas */
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s ease;
            text-align: center;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.15), transparent);
            transition: left 0.4s;
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            border: 1px solid transparent;
        }
        
        .btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(102, 126, 234, 0.25);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
            border: 1px solid transparent;
        }
        
        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(40, 167, 69, 0.25);
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
            border: 1px solid transparent;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(255, 193, 7, 0.25);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
            border: 1px solid transparent;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(220, 53, 69, 0.25);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: 1px solid transparent;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(108, 117, 125, 0.25);
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 4px;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
        }
        
        .btn:disabled::before {
            display: none;
        }
        
        .btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        /* TABLES - Mejoradas */
        .table-responsive {
            overflow-x: auto;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            background: white;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin: 0;
        }
        
        th, td {
            padding: 1.25rem 1rem;
            text-align: left;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
        }
        
        th {
            background: linear-gradient(135deg, #f8f9fc 0%, #eef1f7 100%);
            font-weight: 700;
            color: #2d3748;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        th:first-child {
            border-radius: 15px 0 0 0;
            padding-left: 1.5rem;
        }
        
        th:last-child {
            border-radius: 0 15px 0 0;
            padding-right: 1.5rem;
        }
        
        td:first-child {
            padding-left: 1.5rem;
        }
        
        td:last-child {
            padding-right: 1.5rem;
        }
        
        tr {
            transition: all 0.3s ease;
        }
        
        tbody tr:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.02) 0%, rgba(118, 75, 162, 0.02) 100%);
            transform: scale(1.005);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        tbody tr:last-child td:first-child {
            border-radius: 0 0 0 15px;
        }
        
        tbody tr:last-child td:last-child {
            border-radius: 0 0 15px 0;
        }
        
        tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Mejoras en las celdas */
        .participant-name {
            font-weight: 600;
            color: #2d3748;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        
        .participant-email {
            color: #718096;
            font-size: 0.875rem;
            font-style: italic;
        }
        
        .participant-id {
            font-family: 'Monaco', 'Consolas', monospace;
            background: #f7fafc;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }
        
        /* BADGES */
        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
        }
        
        .empty-state h3 {
            margin: 1rem 0;
            color: #333;
        }
        
        /* BULK ACTIONS - Mejorado */
        .bulk-actions {
            background: linear-gradient(135deg, #f8f9fc 0%, #eef1f7 100%);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            border: 2px solid #e2e8f0;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        .bulk-actions label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0;
        }
        
        .bulk-actions select {
            max-width: 220px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .bulk-actions select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .bulk-actions #contador-info {
            background: rgba(102, 126, 234, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #4c51bf;
        }
        
        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .nav ul {
                flex-direction: column;
                gap: 0;
            }
            
            .nav a {
                padding: 0.75rem 0;
                border-bottom: 1px solid #e9ecef;
                border-bottom-width: 1px !important;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .bulk-actions select, .bulk-actions .btn {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 0.875rem;
            }
            
            th, td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <h1>🎓 Sistema de Certificados</h1>
            </div>
            <div class="user-info">
                <span>👤 <?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
                <a href="../logout.php" class="btn-logout">🚪 Salir</a>
            </div>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="../index.php">🏠 Dashboard</a></li>
                <li><a href="../eventos/listar.php">📅 Eventos</a></li>
                <li><a href="../participantes/listar.php">👥 Participantes</a></li>
                <li><a href="generar.php" class="active">🎓 Certificados</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h2>🎓 Generar Certificados</h2>
                <div class="breadcrumb">
                    <a href="../index.php">Dashboard</a> / 
                    <strong>Certificados</strong>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>✅ Éxito:</strong> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Event Selection -->
        <div class="card">
            <div class="card-header">
                📅 Seleccionar Evento
            </div>
            <div class="card-body">
                <?php if (empty($eventos)): ?>
                    <div class="alert alert-info">
                        <strong>ℹ️ Información:</strong> No hay eventos activos disponibles. 
                        <a href="../eventos/crear.php">Crear nuevo evento</a>
                    </div>
                <?php else: ?>
                    <form method="GET" action="">
                        <div class="form-group">
                            <label for="evento_id">Seleccione un evento para generar certificados:</label>
                            <select name="evento_id" id="evento_id" onchange="this.form.submit()">
                                <option value="">-- Seleccione un evento --</option>
                                <?php foreach ($eventos as $evento): ?>
                                    <option value="<?php echo $evento['id']; ?>" 
                                            <?php echo ($evento['id'] == $evento_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($evento['nombre']); ?> 
                                        (<?php echo date('d/m/Y', strtotime($evento['fecha_inicio'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($evento_seleccionado): ?>
            <!-- Event Info -->
            <div class="card">
                <div class="card-header">
                    📋 Información del Evento
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count($participantes); ?></div>
                            <div class="stat-label">Total Participantes</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">
                                <?php echo count(array_filter($participantes, function($p) { return $p['certificado_id']; })); ?>
                            </div>
                            <div class="stat-label">Con Certificado</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">
                                <?php echo count(array_filter($participantes, function($p) { return !$p['certificado_id']; })); ?>
                            </div>
                            <div class="stat-label">Sin Certificado</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">
                                <?php echo count(array_filter($participantes, function($p) { return $p['plantillas_disponibles'] > 0; })); ?>
                            </div>
                            <div class="stat-label">Con Plantilla</div>
                        </div>
                    </div>
                    
                    <h4><strong>📅 <?php echo htmlspecialchars($evento_seleccionado['nombre']); ?></strong></h4>
                    <p><strong>📍 Modalidad:</strong> <?php echo ucfirst($evento_seleccionado['modalidad']); ?></p>
                    <p><strong>📅 Fechas:</strong> 
                        <?php echo date('d/m/Y', strtotime($evento_seleccionado['fecha_inicio'])); ?> - 
                        <?php echo date('d/m/Y', strtotime($evento_seleccionado['fecha_fin'])); ?>
                    </p>
                    <?php if ($evento_seleccionado['horas_duracion']): ?>
                        <p><strong>⏱️ Duración:</strong> <?php echo $evento_seleccionado['horas_duracion']; ?> horas</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($participantes)): ?>
                <!-- Empty State -->
                <div class="card">
                    <div class="card-body">
                        <div class="empty-state">
                            <div style="font-size: 4rem; margin-bottom: 1rem;">👥</div>
                            <h3>No hay participantes registrados</h3>
                            <p>Agregue participantes a este evento antes de generar certificados.</p>
                            <a href="../participantes/agregar.php?evento_id=<?php echo $evento_id; ?>" class="btn btn-primary">
                                ➕ Agregar Participantes
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Participants Table -->
                <div class="card">
                    <div class="card-header">
                        👥 Participantes del Evento
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="evento_id" value="<?php echo $evento_id; ?>">
                            
                            <!-- Bulk Actions -->
                            <div class="bulk-actions">
                                <label for="accion">Acción masiva:</label>
                                <select name="accion" id="accion">
                                    <option value="">-- Seleccione una acción --</option>
                                    <option value="generar_certificados">🎓 Generar Certificados</option>
                                    <option value="eliminar_certificados">🗑️ Eliminar Certificados</option>
                                </select>
                                <button type="submit" class="btn btn-primary" onclick="return confirmarAccionMasiva()">
                                    ⚡ Ejecutar Acción
                                </button>
                                <span style="color: #666; font-size: 0.9rem;" id="contador-info">
                                    Seleccione participantes y una acción
                                </span>
                            </div>

                            <!-- Table -->
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>
                                                <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes()">
                                            </th>
                                            <th>Nombre Completo</th>
                                            <th>Identificación</th>
                                            <th>Rol</th>
                                            <th>Estado Certificado</th>
                                            <th>Plantilla</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($participantes as $participante): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="participantes[]" 
                                                           value="<?php echo $participante['id']; ?>"
                                                           class="participante-checkbox">
                                                </td>
                                                <td>
                                                    <div class="participant-name">
                                                        <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>
                                                    </div>
                                                    <div class="participant-email">
                                                        <?php echo htmlspecialchars($participante['correo_electronico']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="participant-id">
                                                        <?php echo htmlspecialchars($participante['numero_identificacion']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <?php echo htmlspecialchars(ucfirst($participante['rol'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($participante['certificado_id']): ?>
                                                        <span class="badge badge-success">✅ Generado</span>
                                                        <br>
                                                        <small style="color: #718096; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                                                            <?php echo date('d/m/Y H:i', strtotime($participante['fecha_generacion'])); ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">⏳ Pendiente</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($participante['plantillas_disponibles'] > 0): ?>
                                                        <span class="badge badge-success">✅ Disponible</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">❌ Sin plantilla</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                        <?php if ($participante['certificado_id']): ?>
                                                            <a href="descargar.php?id=<?php echo $participante['certificado_id']; ?>" 
                                                               class="btn btn-sm btn-primary" target="_blank" title="Descargar certificado">
                                                                📥 Descargar
                                                            </a>
                                                            <form method="POST" action="" style="display: inline;">
                                                                <input type="hidden" name="participantes[]" value="<?php echo $participante['id']; ?>">
                                                                <input type="hidden" name="evento_id" value="<?php echo $evento_id; ?>">
                                                                <button type="submit" name="accion" value="eliminar_certificados" 
                                                                        class="btn btn-sm btn-danger"
                                                                        title="Eliminar certificado"
                                                                        onclick="return confirm('¿Eliminar el certificado de <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>?')">
                                                                    🗑️ Eliminar
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="POST" action="../participantes/generar_individual.php" style="display: inline;">
                                                                <input type="hidden" name="participante_id" value="<?php echo $participante['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-success"
                                                                        title="<?php echo $participante['plantillas_disponibles'] == 0 ? 'Sin plantilla configurada' : 'Generar certificado individual'; ?>"
                                                                        <?php echo $participante['plantillas_disponibles'] == 0 ? 'disabled' : ''; ?>>
                                                                    🎓 Generar
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        ⚡ Acciones Rápidas
                    </div>
                    <div class="card-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                            <a href="../eventos/plantillas.php?evento_id=<?php echo $evento_id; ?>" class="btn btn-secondary">
                                🎨 Configurar Plantillas
                            </a>
                            <a href="../participantes/agregar.php?evento_id=<?php echo $evento_id; ?>" class="btn btn-success">
                                ➕ Agregar Participante
                            </a>
                            <a href="../participantes/cargar.php" class="btn btn-primary">
                                📤 Carga Masiva
                            </a>
                            <?php
                            $sin_certificado = count(array_filter($participantes, function($p) { 
                                return !$p['certificado_id'] && $p['plantillas_disponibles'] > 0; 
                            }));
                            if ($sin_certificado > 0):
                            ?>
                                <button type="button" class="btn btn-warning" onclick="generarTodosLosCertificados()">
                                    🎓 Generar Todos (<?php echo $sin_certificado; ?>)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Función para seleccionar/deseleccionar todos los checkboxes
        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('input[name="participantes[]"]:not([disabled])');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Función para confirmar acción masiva
        function confirmarAccionMasiva() {
            const accion = document.getElementById('accion').value;
            const checkboxes = document.querySelectorAll('input[name="participantes[]"]:checked');
            
            if (!accion) {
                alert('❌ Por favor seleccione una acción');
                return false;
            }
            
            if (checkboxes.length === 0) {
                alert('❌ Por favor seleccione al menos un participante');
                return false;
            }
            
            let mensaje = '';
            switch(accion) {
                case 'generar_certificados':
                    mensaje = `¿Está seguro de generar certificados para ${checkboxes.length} participante(s)?`;
                    break;
                case 'eliminar_certificados':
                    mensaje = `¿Está seguro de eliminar certificados de ${checkboxes.length} participante(s)?\n\n⚠️ Esta acción eliminará permanentemente los archivos de certificado.`;
                    break;
                default:
                    mensaje = `¿Está seguro de ejecutar esta acción para ${checkboxes.length} participante(s)?`;
            }
            
            return confirm(mensaje);
        }

        // Función para generar todos los certificados disponibles
        function generarTodosLosCertificados() {
            const checkboxes = document.querySelectorAll('input[name="participantes[]"]:not([disabled])');
            const accionSelect = document.getElementById('accion');
            
            // Deseleccionar todos primero
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Seleccionar solo los que no tienen certificado
            const participantesSinCertificado = [];
            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const estadoBadge = row.querySelector('.badge-warning');
                if (estadoBadge && estadoBadge.textContent.includes('Pendiente')) {
                    checkbox.checked = true;
                    participantesSinCertificado.push(checkbox);
                }
            });
            
            if (participantesSinCertificado.length === 0) {
                alert('❌ No hay participantes sin certificado disponibles para generar');
                return;
            }
            
            // Seleccionar la acción de generar certificados
            accionSelect.value = 'generar_certificados';
            
            // Confirmar y enviar
            if (confirm(`¿Está seguro de generar certificados para ${participantesSinCertificado.length} participantes sin certificado?`)) {
                document.querySelector('form').submit();
            }
        }

        // Actualizar contador de seleccionados
        function actualizarContadorSeleccionados() {
            const checkboxes = document.querySelectorAll('input[name="participantes[]"]:checked');
            const contador = document.getElementById('contador-seleccionados');
            if (contador) {
                contador.textContent = checkboxes.length;
            }
        }

        // Agregar event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Event listener para checkboxes individuales
            const checkboxes = document.querySelectorAll('input[name="participantes[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    // Actualizar estado del checkbox "seleccionar todos"
                    const selectAll = document.getElementById('select-all');
                    const checkboxesActivos = document.querySelectorAll('input[name="participantes[]"]');
                    const checkboxesSeleccionados = document.querySelectorAll('input[name="participantes[]"]:checked');
                    
                    selectAll.checked = checkboxesActivos.length === checkboxesSeleccionados.length && checkboxesActivos.length > 0;
                    selectAll.indeterminate = checkboxesSeleccionados.length > 0 && checkboxesActivos.length !== checkboxesSeleccionados.length;
                    
                    actualizarContadorSeleccionados();
                });
            });

            // Auto-scroll hacia la tabla si hay evento seleccionado
            const eventoId = new URLSearchParams(window.location.search).get('evento_id');
            if (eventoId && document.querySelector('.table-responsive')) {
                setTimeout(() => {
                    document.querySelector('.table-responsive').scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }, 100);
            }

            // Inicializar contador
            actualizarContadorSeleccionados();
        });

        // Función para mostrar preview del certificado (opcional)
        function previewCertificado(participanteId) {
            // Esta función se puede implementar para mostrar un preview
            console.log('Preview certificado para participante:', participanteId);
        }

        // Función para validar plantillas antes de generar
        function validarPlantillas() {
            const participantesSinPlantilla = document.querySelectorAll('tr').length;
            // Implementar validación de plantillas si es necesario
        }

        // Notificaciones toast (opcional)
        function mostrarNotificacion(mensaje, tipo = 'info') {
            // Implementar sistema de notificaciones toast si se desea
            console.log(`${tipo.toUpperCase()}: ${mensaje}`);
        }

        // Función para recargar datos sin refresh completo (opcional)
        function recargarDatos() {
            const eventoId = document.getElementById('evento_id').value;
            if (eventoId) {
                window.location.href = `generar.php?evento_id=${eventoId}`;
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + A para seleccionar todos
            if ((e.ctrlKey || e.metaKey) && e.key === 'a' && e.target.type !== 'text') {
                e.preventDefault();
                document.getElementById('select-all').click();
            }
            
            // Escape para deseleccionar todos
            if (e.key === 'Escape') {
                document.getElementById('select-all').checked = false;
                toggleAllCheckboxes();
            }
        });
    </script>
</body>
</html>