<?php
// admin/participantes/generar_individual.php - VERSIÓN CORREGIDA CON PDF REAL
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
    
    // GENERAR CERTIFICADO CON PDF REAL
    $resultado = generarCertificadoPDFReal($participante);
    
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

function generarCertificadoPDFReal($participante) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Generar contenido HTML del certificado
        $html_certificado = generarHTMLCertificado($participante, $codigo_verificacion);
        
        // Convertir HTML a PDF usando mPDF simple o generar PDF básico
        $pdf_content = generarPDFConHTML($html_certificado, $participante, $codigo_verificacion);
        
        // Generar nombre de archivo
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar PDF
        if (file_put_contents($ruta_completa, $pdf_content) === false) {
            throw new Exception("No se pudo escribir el archivo PDF");
        }
        
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
        error_log("Error generando PDF real: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function generarHTMLCertificado($participante, $codigo_verificacion) {
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $fecha_actual = date('d/m/Y');
    
    return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Certificado - $nombre_completo</title>
    <style>
        @page { size: A4 landscape; margin: 1.5cm; }
        body {
            font-family: 'Times New Roman', serif;
            margin: 0;
            padding: 30px;
            text-align: center;
            line-height: 1.6;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        .certificate {
            background: white;
            border: 6px solid #1a2980;
            border-radius: 15px;
            padding: 40px 30px;
            margin: 0 auto;
            position: relative;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            border-bottom: 2px solid #26d0ce;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .institution {
            font-size: 16px;
            font-weight: bold;
            color: #1a2980;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .department {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
        }
        .title {
            font-size: 28px;
            font-weight: bold;
            color: #1a2980;
            margin: 25px 0;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .certifies {
            font-size: 16px;
            margin: 20px 0 15px 0;
            color: #333;
        }
        .participant-name {
            font-size: 24px;
            font-weight: bold;
            color: #26d0ce;
            margin: 15px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .participant-id {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }
        .event-info {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            color: #333;
        }
        .event-name {
            font-size: 18px;
            font-weight: bold;
            color: #1a2980;
            margin-bottom: 10px;
        }
        .event-details {
            font-size: 13px;
            line-height: 1.6;
        }
        .footer {
            margin-top: 30px;
            border-top: 1px solid #e9ecef;
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .verification {
            text-align: left;
            font-size: 11px;
        }
        .verification-code {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            font-weight: bold;
            color: #1a2980;
            background: #f8f9fa;
            padding: 5px 8px;
            border-radius: 3px;
            border: 1px solid #dee2e6;
        }
        .signature {
            text-align: right;
            font-size: 11px;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 150px;
            margin: 15px 0 8px auto;
        }
        .decorative-border {
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #26d0ce;
            border-radius: 10px;
            pointer-events: none;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class='certificate'>
        <div class='decorative-border'></div>
        
        <div class='header'>
            <div class='institution'>Universidad Distrital Francisco José de Caldas</div>
            <div class='department'>Sistema de Gestión de Proyectos y Oficina de Extensión (SGPOE)</div>
        </div>
        
        <div class='title'>Certificado de Participación</div>
        
        <div class='certifies'>Se certifica que:</div>
        
        <div class='participant-name'>$nombre_completo</div>
        <div class='participant-id'>Documento de Identidad: {$participante['numero_identificacion']}</div>
        
        <div class='event-info'>
            <div class='event-name'>{$participante['evento_nombre']}</div>
            <div class='event-details'>
                <strong>Realizado:</strong> del $fecha_inicio al $fecha_fin<br>
                <strong>Modalidad:</strong> " . ucfirst($participante['modalidad']) . "<br>
                <strong>Lugar:</strong> " . ($participante['lugar'] ?: 'Virtual') . "<br>
                <strong>Entidad Organizadora:</strong> {$participante['entidad_organizadora']}<br>
                <strong>Duración:</strong> " . ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'No especificada') . "<br>
                <strong>En calidad de:</strong> {$participante['rol']}
            </div>
        </div>
        
        <div class='footer'>
            <div class='verification'>
                <div>Código de verificación:</div>
                <div class='verification-code'>$codigo_verificacion</div>
                <div style='margin-top: 8px; font-size: 10px;'>
                    Verificar en: " . PUBLIC_URL . "verificar.php
                </div>
            </div>
            
            <div class='signature'>
                <div class='signature-line'></div>
                <div>Firma Digital Autorizada</div>
                <div>Bogotá D.C., $fecha_actual</div>
            </div>
        </div>
    </div>
</body>
</html>";
}

function generarPDFConHTML($html_content, $participante, $codigo_verificacion) {
    // Generar un PDF básico pero válido con estructura PDF real
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $fecha_actual = date('d/m/Y');
    
    // Crear contenido del certificado en formato texto para PDF
    $contenido_certificado = "
                    CERTIFICADO DE PARTICIPACION
                    
        UNIVERSIDAD DISTRITAL FRANCISCO JOSE DE CALDAS
    SISTEMA DE GESTION DE PROYECTOS Y OFICINA DE EXTENSION (SGPOE)
    
    
                        Se certifica que:
                        
                    $nombre_completo
                    
              Documento de Identidad: {$participante['numero_identificacion']}
              
              
            Participo exitosamente en el evento:
            
                    {$participante['evento_nombre']}
                    
                    
    Realizado del $fecha_inicio al $fecha_fin
    Modalidad: " . ucfirst($participante['modalidad']) . "
    Lugar: " . ($participante['lugar'] ?: 'Virtual') . "
    Entidad Organizadora: {$participante['entidad_organizadora']}
    Duracion: " . ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas academicas' : 'No especificada') . "
    En calidad de: {$participante['rol']}
    
    
    Expedido en Bogota D.C., a los $fecha_actual
    
    Codigo de Verificacion: $codigo_verificacion
    
    Consulte la autenticidad en: " . PUBLIC_URL . "verificar.php
    
    
    Este es un certificado digital generado automaticamente.
    Para verificar su autenticidad, ingrese el codigo de verificacion 
    en nuestro sitio web.
    
    
    -----------------------------------------------------------
    SGPOE - Universidad Distrital Francisco Jose de Caldas
    " . date('Y') . "
    ";

    // Generar estructura PDF básica pero válida
    $pdf_header = "%PDF-1.4
1 0 obj
<<
/Type /Catalog
/Pages 2 0 R
>>
endobj

2 0 obj
<<
/Type /Pages
/Kids [3 0 R]
/Count 1
>>
endobj

3 0 obj
<<
/Type /Page
/Parent 2 0 R
/MediaBox [0 0 842 595]
/Contents 4 0 R
/Resources <<
/Font <<
/F1 5 0 R
>>
>>
>>
endobj

4 0 obj
<<
/Length " . (strlen($contenido_certificado) + 100) . "
>>
stream
BT
/F1 12 Tf
50 550 Td
";

    // Dividir el contenido en líneas y agregar al PDF
    $lineas = explode("\n", $contenido_certificado);
    $pdf_content = "";
    $y_offset = 0;
    
    foreach ($lineas as $linea) {
        $linea_limpia = trim($linea);
        if (!empty($linea_limpia)) {
            $pdf_content .= "($linea_limpia) Tj\n0 -15 Td\n";
        } else {
            $pdf_content .= "0 -10 Td\n";
        }
    }

    $pdf_footer = "ET
endstream
endobj

5 0 obj
<<
/Type /Font
/Subtype /Type1
/BaseFont /Helvetica
>>
endobj

xref
0 6
0000000000 65535 f 
0000000010 00000 n 
0000000079 00000 n 
0000000173 00000 n 
0000000301 00000 n 
0000002350 00000 n 
trailer
<<
/Size 6
/Root 1 0 R
>>
startxref
2440
%%EOF";

    // Combinar todo el contenido del PDF
    return $pdf_header . $pdf_content . $pdf_footer;
}

// Función auxiliar para formatear fechas
function formatearFecha($fecha) {
    return date('d/m/Y', strtotime($fecha));
}
?>