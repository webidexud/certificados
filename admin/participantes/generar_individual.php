<?php
// admin/participantes/generar_individual.php - VERSIÓN SIMPLIFICADA PARA DEPURACIÓN
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

// Habilitar reporte de errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log para depuración
error_log("=== INICIANDO GENERACIÓN INDIVIDUAL ===");

verificarAutenticacion();

// Solo acepta requests POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Método no permitido: " . $_SERVER['REQUEST_METHOD']);
    $_SESSION['error_mensaje'] = 'Método no permitido';
    header('Location: listar.php');
    exit;
}

$participante_id = isset($_POST['participante_id']) ? intval($_POST['participante_id']) : 0;
error_log("Participante ID recibido: " . $participante_id);

if (!$participante_id) {
    error_log("ID de participante no válido");
    $_SESSION['error_mensaje'] = 'ID de participante no válido';
    header('Location: listar.php');
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    error_log("Conexión a BD establecida");
    
    // Verificar que el participante existe
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
    
    error_log("Participante encontrado: " . ($participante ? "SÍ" : "NO"));
    
    if (!$participante) {
        error_log("Participante no encontrado en BD");
        $_SESSION['error_mensaje'] = 'Participante no encontrado';
        header('Location: listar.php');
        exit;
    }
    
    // Verificar si ya tiene certificado
    if ($participante['tiene_certificado'] > 0) {
        error_log("Participante ya tiene certificado");
        $_SESSION['error_mensaje'] = 'Este participante ya tiene un certificado generado';
        header('Location: listar.php');
        exit;
    }
    
    error_log("Iniciando generación de certificado para: " . $participante['nombres'] . ' ' . $participante['apellidos']);
    
    // GENERAR CERTIFICADO SIMPLE - SOLO PDF POR AHORA
    $resultado = generarCertificadoPDFSimple($participante);
    
    if ($resultado['success']) {
        error_log("Certificado generado exitosamente: " . $resultado['codigo_verificacion']);
        $_SESSION['success_mensaje'] = 'Certificado PDF generado exitosamente para ' . 
                                      $participante['nombres'] . ' ' . $participante['apellidos'] . 
                                      '. Código: ' . $resultado['codigo_verificacion'];
    } else {
        error_log("Error al generar certificado: " . $resultado['error']);
        $_SESSION['error_mensaje'] = 'Error al generar certificado: ' . $resultado['error'];
    }
    
} catch (Exception $e) {
    error_log("Excepción capturada: " . $e->getMessage());
    $_SESSION['error_mensaje'] = 'Error interno del sistema: ' . $e->getMessage();
}

// Redirigir de vuelta a la lista
error_log("Redirigiendo a listar.php");
header('Location: listar.php');
exit;

function generarCertificadoPDFSimple($participante) {
    error_log("=== FUNCIÓN GENERAR PDF SIMPLE ===");
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        error_log("Código generado: " . $codigo_verificacion);
        
        // Generar contenido del certificado
        $contenido_certificado = generarContenidoPDFBasico($participante, $codigo_verificacion);
        
        // Generar nombre de archivo único
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        error_log("Ruta del archivo: " . $ruta_completa);
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
            error_log("Directorio creado: " . GENERATED_PATH . 'certificados/');
        }
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $contenido_certificado) === false) {
            throw new Exception("No se pudo escribir el archivo PDF");
        }
        
        error_log("Archivo guardado exitosamente");
        
        // Generar hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d'));
        
        // Insertar en base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, 'pdf', NOW())
        ");
        
        $resultado_bd = $stmt->execute([
            $participante['id'],
            $participante['evento_id'],
            $codigo_verificacion,
            $nombre_archivo,
            $hash_validacion
        ]);
        
        if (!$resultado_bd) {
            throw new Exception("Error al insertar en base de datos");
        }
        
        error_log("Registro insertado en BD exitosamente");
        
        // Registrar auditoría
        registrarAuditoria('GENERAR_CERTIFICADO_INDIVIDUAL', 'certificados', $db->lastInsertId(), null, [
            'participante_id' => $participante['id'],
            'codigo_verificacion' => $codigo_verificacion,
            'tipo_archivo' => 'pdf'
        ]);
        
        return [
            'success' => true,
            'codigo_verificacion' => $codigo_verificacion,
            'tipo' => 'pdf',
            'archivo' => $nombre_archivo
        ];
        
    } catch (Exception $e) {
        error_log("Error en generarCertificadoPDFSimple: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function generarContenidoPDFBasico($participante, $codigo_verificacion) {
    error_log("Generando contenido PDF");
    
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $fecha_actual = date('d/m/Y');
    
    $contenido = "CERTIFICADO DE PARTICIPACIÓN

UNIVERSIDAD DISTRITAL FRANCISCO JOSÉ DE CALDAS
SISTEMA DE GESTIÓN DE PROYECTOS Y OFICINA DE EXTENSIÓN (SGPOE)

Se certifica que:

{$nombre_completo}
Documento de Identidad: {$participante['numero_identificacion']}

Participó exitosamente en el evento:

{$participante['evento_nombre']}

Realizado del " . formatearFecha($participante['fecha_inicio']) . " al " . formatearFecha($participante['fecha_fin']) . "
Modalidad: " . ucfirst($participante['modalidad']) . "
Lugar: " . ($participante['lugar'] ?: 'Virtual') . "
Entidad Organizadora: {$participante['entidad_organizadora']}
Duración: " . ($participante['horas_duracion'] ?: 'No especificada') . " horas académicas

En calidad de: {$participante['rol']}

Expedido en Bogotá D.C., a los {$fecha_actual}

Código de Verificación: {$codigo_verificacion}
Consulte la autenticidad en: " . PUBLIC_URL . "verificar.php

Este es un certificado digital generado automáticamente.
Para verificar su autenticidad, ingrese el código de verificación en nuestro sitio web.

---
SGPOE - Universidad Distrital Francisco José de Caldas
" . date('Y');

    error_log("Contenido PDF generado, longitud: " . strlen($contenido));
    return $contenido;
}
?>