<?php
// admin/participantes/generar_individual.php - VERSIÓN COMPLETA CON PLANTILLAS SVG
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

// Habilitar reporte de errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    
    // GENERAR CERTIFICADO CON PLANTILLA SVG
    $resultado = generarCertificadoConPlantilla($participante);
    
    if ($resultado['success']) {
        $_SESSION['success_mensaje'] = 'Certificado generado exitosamente para ' . 
                                      $participante['nombres'] . ' ' . $participante['apellidos'] . 
                                      '. Código: ' . $resultado['codigo_verificacion'] .
                                      ' (Tipo: ' . strtoupper($resultado['tipo']) . ')';
    } else {
        $_SESSION['error_mensaje'] = 'Error al generar certificado: ' . $resultado['error'];
    }
    
} catch (Exception $e) {
    $_SESSION['error_mensaje'] = 'Error interno del sistema: ' . $e->getMessage();
}

// Redirigir de vuelta a la lista
header('Location: listar.php');
exit;

function generarCertificadoConPlantilla($participante) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Buscar plantilla SVG para este evento/rol
        $stmt = $db->prepare("
            SELECT archivo_plantilla, nombre_plantilla, rol as plantilla_rol
            FROM plantillas_certificados 
            WHERE evento_id = ? AND (rol = ? OR rol = 'General')
            ORDER BY CASE WHEN rol = ? THEN 1 ELSE 2 END
            LIMIT 1
        ");
        $stmt->execute([$participante['evento_id'], $participante['rol'], $participante['rol']]);
        $plantilla = $stmt->fetch();
        
        if ($plantilla && file_exists(TEMPLATE_PATH . $plantilla['archivo_plantilla'])) {
            // Usar plantilla SVG
            $contenido_plantilla = file_get_contents(TEMPLATE_PATH . $plantilla['archivo_plantilla']);
            
            if ($contenido_plantilla === false) {
                throw new Exception("No se pudo leer la plantilla SVG");
            }
            
            $contenido_final = procesarPlantillaSVG($contenido_plantilla, $participante, $codigo_verificacion);
            $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
            $tipo_archivo = 'svg';
            $plantilla_usada = $plantilla['nombre_plantilla'] . ' (Rol: ' . $plantilla['plantilla_rol'] . ')';
            
        } else {
            // Fallback a PDF básico si no hay plantilla
            $contenido_final = generarPDFBasico($participante, $codigo_verificacion);
            $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
            $tipo_archivo = 'pdf';
            $plantilla_usada = 'PDF básico (sin plantilla SVG)';
        }
        
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $contenido_final) === false) {
            throw new Exception("No se pudo escribir el archivo del certificado");
        }
        
        // Verificar que el archivo se guardó correctamente
        if (!file_exists($ruta_completa) || filesize($ruta_completa) === 0) {
            throw new Exception("El archivo del certificado no se guardó correctamente");
        }
        
        // Generar hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d'));
        
        // Insertar en base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $resultado_bd = $stmt->execute([
            $participante['id'],
            $participante['evento_id'],
            $codigo_verificacion,
            $nombre_archivo,
            $hash_validacion,
            $tipo_archivo
        ]);
        
        if (!$resultado_bd) {
            throw new Exception("Error al insertar el certificado en la base de datos");
        }
        
        $certificado_id = $db->lastInsertId();
        
        // Registrar auditoría
        registrarAuditoria('GENERAR_CERTIFICADO_INDIVIDUAL', 'certificados', $certificado_id, null, [
            'participante_id' => $participante['id'],
            'codigo_verificacion' => $codigo_verificacion,
            'tipo_archivo' => $tipo_archivo,
            'plantilla_usada' => $plantilla_usada,
            'tamaño_archivo' => filesize($ruta_completa)
        ]);
        
        return [
            'success' => true,
            'codigo_verificacion' => $codigo_verificacion,
            'tipo' => $tipo_archivo,
            'archivo' => $nombre_archivo,
            'plantilla_usada' => $plantilla_usada
        ];
        
    } catch (Exception $e) {
        error_log("Error generando certificado con plantilla: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function procesarPlantillaSVG($contenido_plantilla, $participante, $codigo_verificacion) {
    // Variables de reemplazo completas
    $variables = [
        // DATOS DEL PARTICIPANTE
        '{{nombres}}' => $participante['nombres'],
        '{{apellidos}}' => $participante['apellidos'],
        '{{numero_identificacion}}' => $participante['numero_identificacion'],
        '{{correo_electronico}}' => $participante['correo_electronico'] ?? '',
        '{{telefono}}' => $participante['telefono'] ?? '',
        '{{institucion}}' => $participante['institucion'] ?? '',
        '{{rol}}' => $participante['rol'],
        
        // DATOS DEL EVENTO
        '{{evento_nombre}}' => $participante['evento_nombre'],
        '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
        '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
        '{{entidad_organizadora}}' => $participante['entidad_organizadora'],
        '{{modalidad}}' => ucfirst($participante['modalidad']),
        '{{lugar}}' => $participante['lugar'] ?: 'Virtual',
        '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
        '{{descripcion}}' => $participante['descripcion'] ?? '',
        
        // DATOS DEL CERTIFICADO
        '{{codigo_verificacion}}' => $codigo_verificacion,
        '{{fecha_generacion}}' => date('d/m/Y H:i'),
        '{{fecha_emision}}' => date('d/m/Y'),
        '{{año}}' => date('Y'),
        '{{mes}}' => date('m'),
        '{{dia}}' => date('d'),
        
        // URLs Y ENLACES
        '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion,
        '{{numero_certificado}}' => 'CERT-' . date('Y') . '-' . str_pad($participante['id'], 6, '0', STR_PAD_LEFT),
        
        // EXTRAS ÚTILES
        '{{nombre_completo}}' => $participante['nombres'] . ' ' . $participante['apellidos'],
        '{{iniciales}}' => strtoupper(substr($participante['nombres'], 0, 1) . substr($participante['apellidos'], 0, 1)),
        '{{mes_nombre}}' => obtenerNombreMes(date('n')),
        '{{año_completo}}' => date('Y'),
        '{{duracion_texto}}' => ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'Duración no especificada'),
        '{{modalidad_completa}}' => 'Modalidad ' . ucfirst($participante['modalidad']),
        '{{periodo_evento}}' => formatearFecha($participante['fecha_inicio']) . ' al ' . formatearFecha($participante['fecha_fin']),
        
        // DATOS INSTITUCIONALES
        '{{firma_digital}}' => 'Certificado Digital Verificado',
        '{{sello_institucional}}' => 'Universidad Distrital Francisco José de Caldas',
        '{{departamento}}' => 'Sistema de Gestión de Proyectos y Oficina de Extensión (SGPOE)'
    ];
    
    // Reemplazar variables en la plantilla
    $contenido_procesado = $contenido_plantilla;
    foreach ($variables as $variable => $valor) {
        // Usar htmlspecialchars para caracteres XML seguros
        $valor_seguro = htmlspecialchars($valor, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $contenido_procesado = str_replace($variable, $valor_seguro, $contenido_procesado);
    }
    
    // Limpiar variables no utilizadas (opcional)
    $contenido_procesado = preg_replace('/\{\{[^}]+\}\}/', '', $contenido_procesado);
    
    // Asegurar que el SVG tiene la declaración XML correcta
    if (strpos($contenido_procesado, '<?xml') === false) {
        $contenido_procesado = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $contenido_procesado;
    }
    
    return $contenido_procesado;
}

function generarPDFBasico($participante, $codigo_verificacion) {
    // Fallback PDF básico cuando no hay plantilla SVG
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $fecha_actual = date('d/m/Y');
    
    // Contenido del certificado en texto plano
    $contenido_certificado = "CERTIFICADO DE PARTICIPACIÓN

UNIVERSIDAD DISTRITAL FRANCISCO JOSÉ DE CALDAS
SISTEMA DE GESTIÓN DE PROYECTOS Y OFICINA DE EXTENSIÓN (SGPOE)

Se certifica que:

$nombre_completo
Documento de Identidad: {$participante['numero_identificacion']}

Participó exitosamente en el evento:

{$participante['evento_nombre']}

Realizado del $fecha_inicio al $fecha_fin
Modalidad: " . ucfirst($participante['modalidad']) . "
Lugar: " . ($participante['lugar'] ?: 'Virtual') . "
Entidad Organizadora: {$participante['entidad_organizadora']}
Duración: " . ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'No especificada') . "

En calidad de: {$participante['rol']}

Expedido en Bogotá D.C., a los $fecha_actual

Código de Verificación: $codigo_verificacion
Consulte la autenticidad en: " . PUBLIC_URL . "verificar.php

Este es un certificado digital generado automáticamente.
Para verificar su autenticidad, ingrese el código de verificación en nuestro sitio web.

---
SGPOE - Universidad Distrital Francisco José de Caldas
" . date('Y');

    return $contenido_certificado;
}

// Función auxiliar para obtener nombre del mes
function obtenerNombreMes($numero_mes) {
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    return $meses[$numero_mes] ?? 'Mes';
}

// Función auxiliar para formatear fechas
function formatearFecha($fecha) {
    return date('d/m/Y', strtotime($fecha));
}
?>