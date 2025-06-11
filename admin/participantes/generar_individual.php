<?php
// admin/participantes/generar_individual.php - VERSIÓN CON PLANTILLAS SVG
require_once '../../config/config.php';
require_once '../../includes/funciones.php';
require_once '../../includes/funciones_svg.php';

verificarAutenticacion();

// Solo acepta requests POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_mensaje'] = 'Método no permitido';
    header('Location: listar.php');
    exit;
}

$participante_id = isset($_POST['participante_id']) ? intval($_POST['participante_id']) : 0;

if (!$participante_id) {
    $_SESSION['error_mensaje'] = 'ID de participante no válido';
    header('Location: listar.php');
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener datos completos del participante
    $stmt = $db->prepare("
        SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
               e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion, e.descripcion,
               (SELECT COUNT(*) FROM certificados c WHERE c.participante_id = p.id) as tiene_certificado
        FROM participantes p 
        JOIN eventos e ON p.evento_id = e.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$participante_id]);
    $participante = $stmt->fetch();
    
    if (!$participante) {
        $_SESSION['error_mensaje'] = 'Participante no encontrado';
        header('Location: listar.php');
        exit;
    }
    
    // Verificar si ya tiene certificado
    if ($participante['tiene_certificado'] > 0) {
        $_SESSION['error_mensaje'] = 'Este participante ya tiene un certificado generado';
        header('Location: listar.php');
        exit;
    }
    
    // BUSCAR PLANTILLA PARA EL ROL DEL PARTICIPANTE
    $stmt = $db->prepare("
        SELECT * FROM plantillas_certificados 
        WHERE evento_id = ? AND (rol = ? OR rol = 'General') 
        ORDER BY CASE WHEN rol = ? THEN 1 ELSE 2 END 
        LIMIT 1
    ");
    $stmt->execute([$participante['evento_id'], $participante['rol'], $participante['rol']]);
    $plantilla = $stmt->fetch();
    
    if (!$plantilla) {
        $_SESSION['error_mensaje'] = 'No hay plantilla configurada para el rol "' . $participante['rol'] . '" en este evento. Configure la plantilla primero.';
        header('Location: listar.php');
        exit;
    }
    
    // Verificar que el archivo de plantilla existe
    $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
    if (!file_exists($ruta_plantilla)) {
        $_SESSION['error_mensaje'] = 'El archivo de plantilla no existe. Suba nuevamente la plantilla.';
        header('Location: listar.php');
        exit;
    }
    
    // GENERAR CERTIFICADO CON LA PLANTILLA
    $resultado = generarCertificadoConPlantilla($participante, $plantilla);
    
    if ($resultado['success']) {
        $_SESSION['success_mensaje'] = 'Certificado generado exitosamente para ' . 
                                      $participante['nombres'] . ' ' . $participante['apellidos'] . 
                                      '. Código: ' . $resultado['codigo_verificacion'];
    } else {
        $_SESSION['error_mensaje'] = 'Error al generar certificado: ' . $resultado['error'];
    }
    
} catch (Exception $e) {
    $_SESSION['error_mensaje'] = 'Error interno del sistema: ' . $e->getMessage();
}

// Redirigir de vuelta a la lista
header('Location: listar.php');
exit;

function generarCertificadoConPlantilla($participante, $plantilla) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Leer contenido de la plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        $contenido_svg = file_get_contents($ruta_plantilla);
        
        if ($contenido_svg === false) {
            throw new Exception("No se pudo leer la plantilla SVG");
        }
        
        // Preparar datos para reemplazar en la plantilla
        $datos_certificado = [
            '{{nombres}}' => $participante['nombres'],
            '{{apellidos}}' => $participante['apellidos'],
            '{{numero_identificacion}}' => $participante['numero_identificacion'],
            '{{correo_electronico}}' => $participante['correo_electronico'],
            '{{rol}}' => $participante['rol'],
            '{{telefono}}' => $participante['telefono'] ?: '',
            '{{institucion}}' => $participante['institucion'] ?: '',
            
            // Datos del evento
            '{{evento_nombre}}' => $participante['evento_nombre'],
            '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
            '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
            '{{entidad_organizadora}}' => $participante['entidad_organizadora'],
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => $participante['lugar'] ?: 'Virtual',
            '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
            
            // Datos del certificado
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{fecha_emision}}' => date('d/m/Y'),
            '{{año}}' => date('Y'),
            '{{mes}}' => date('m'),
            '{{dia}}' => date('d'),
            
            // URLs y enlaces
            '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion,
            '{{numero_certificado}}' => 'CERT-' . date('Y') . '-' . str_pad($participante['id'], 6, '0', STR_PAD_LEFT),
            
            // Extras
            '{{nombre_completo}}' => $participante['nombres'] . ' ' . $participante['apellidos'],
            '{{iniciales}}' => strtoupper(substr($participante['nombres'], 0, 1) . substr($participante['apellidos'], 0, 1)),
            '{{duracion_texto}}' => ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'Duración no especificada'),
            '{{modalidad_completa}}' => 'Modalidad ' . ucfirst($participante['modalidad']),
        ];
        
        // Procesar el SVG con manejo de texto largo
        $svg_procesado = procesarTextoSVG($contenido_svg, $datos_certificado);
        
        // Procesar nombres largos específicamente
        $svg_procesado = procesarNombresLargos($svg_procesado, $participante['nombres'], $participante['apellidos']);
        
        // Procesar eventos largos
        $svg_procesado = procesarEventosLargos($svg_procesado, $participante['evento_nombre']);
        
        // Optimizar SVG para mejor renderizado
        $svg_procesado = optimizarSVGTexto($svg_procesado);
        
        // Generar nombre de archivo
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar SVG procesado
        if (file_put_contents($ruta_completa, $svg_procesado) === false) {
            throw new Exception("No se pudo escribir el archivo SVG");
        }
        
        // Generar hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d'));
        
        // Extraer dimensiones del SVG
        $dimensiones = extraerDimensionesSVG($svg_procesado);
        
        // Insertar en base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, dimensiones, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, 'svg', ?, NOW())
        ");
        
        $resultado_bd = $stmt->execute([
            $participante['id'],
            $participante['evento_id'],
            $codigo_verificacion,
            $nombre_archivo,
            $hash_validacion,
            json_encode($dimensiones)
        ]);
        
        if (!$resultado_bd) {
            throw new Exception("Error al insertar en base de datos");
        }
        
        // Registrar auditoría
        registrarAuditoria('GENERAR_CERTIFICADO_SVG', 'certificados', $db->lastInsertId(), null, [
            'participante_id' => $participante['id'],
            'codigo_verificacion' => $codigo_verificacion,
            'tipo_archivo' => 'svg',
            'plantilla_usada' => $plantilla['nombre_plantilla'],
            'rol' => $participante['rol']
        ]);
        
        return [
            'success' => true,
            'codigo_verificacion' => $codigo_verificacion,
            'tipo' => 'svg',
            'archivo' => $nombre_archivo,
            'plantilla' => $plantilla['nombre_plantilla']
        ];
        
    } catch (Exception $e) {
        error_log("Error generando certificado SVG: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function extraerDimensionesSVG($contenido_svg) {
    $ancho = 1200;
    $alto = 850;
    
    if (preg_match('/width=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $ancho = intval($matches[1]);
    }
    
    if (preg_match('/height=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $alto = intval($matches[1]);
    }
    
    if (preg_match('/viewBox=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $viewBox = explode(' ', $matches[1]);
        if (count($viewBox) >= 4) {
            $ancho = intval($viewBox[2]);
            $alto = intval($viewBox[3]);
        }
    }
    
    return ['ancho' => $ancho, 'alto' => $alto];
}
?>