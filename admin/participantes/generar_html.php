<?php
// admin/participantes/generar_html.php - GENERADOR HTML SIMPLE COMO ALTERNATIVA
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$participante_id = isset($_POST['participante_id']) ? intval($_POST['participante_id']) : 0;

if (!$participante_id) {
    $_SESSION['error_mensaje'] = 'ID de participante no válido';
    header('Location: listar.php');
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener datos del participante
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
    
    if ($participante['tiene_certificado'] > 0) {
        $_SESSION['error_mensaje'] = 'Este participante ya tiene un certificado generado';
        header('Location: listar.php');
        exit;
    }
    
    // GENERAR CERTIFICADO HTML
    $resultado = generarCertificadoHTML($participante);
    
    if ($resultado['success']) {
        $_SESSION['success_mensaje'] = 'Certificado HTML generado exitosamente para ' . 
                                      $participante['nombres'] . ' ' . $participante['apellidos'] . 
                                      '. Código: ' . $resultado['codigo_verificacion'];
    } else {
        $_SESSION['error_mensaje'] = 'Error al generar certificado: ' . $resultado['error'];
    }
    
} catch (Exception $e) {
    $_SESSION['error_mensaje'] = 'Error interno: ' . $e->getMessage();
}

header('Location: listar.php');
exit;

function generarCertificadoHTML($participante) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Generar contenido HTML completo
        $html_completo = generarHTMLCompleto($participante, $codigo_verificacion);
        
        // Generar nombre de archivo
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.html';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar archivo HTML
        if (file_put_contents($ruta_completa, $html_completo) === false) {
            throw new Exception("No se pudo escribir el archivo HTML");
        }
        
        // Generar hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d'));
        
        // Insertar en base de datos - guardamos como 'html' en lugar de 'pdf'
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, 'html', NOW())
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
        registrarAuditoria('GENERAR_CERTIFICADO_HTML', 'certificados', $db->lastInsertId(), null, [
            'participante_id' => $participante['id'],
            'codigo_verificacion' => $codigo_verificacion,
            'tipo_archivo' => 'html'
        ]);
        
        return [
            'success' => true,
            'codigo_verificacion' => $codigo_verificacion,
            'tipo' => 'html',
            'archivo' => $nombre_archivo
        ];
        
    } catch (Exception $e) {
        error_log("Error generando HTML: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function generarHTMLCompleto($participante, $codigo_verificacion) {
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $fecha_inicio = date('d/m/Y', strtotime($participante['fecha_inicio']));
    $fecha_fin = date('d/m/Y', strtotime($participante['fecha_fin']));
    $fecha_actual = date('d/m/Y');
    
    return "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Certificado - $nombre_completo</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Georgia, serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .certificate {
            background: white;
            width: 100%;
            max-width: 1000px;
            border: 8px solid #1a2980;
            border-radius: 20px;
            padding: 60px 50px;
            box-shadow: 0 0 30px rgba(0,0,0,0.2);
            position: relative;
            text-align: center;
        }
        
        .certificate::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            bottom: 20px;
            border: 2px solid #26d0ce;
            border-radius: 15px;
            pointer-events: none;
        }
        
        .header {
            border-bottom: 3px solid #26d0ce;
            padding-bottom: 30px;
            margin-bottom: 40px;
        }
        
        .institution {
            font-size: 20px;
            font-weight: bold;
            color: #1a2980;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .department {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            font-style: italic;
        }
        
        .title {
            font-size: 42px;
            font-weight: bold;
            color: #1a2980;
            margin: 40px 0;
            text-transform: uppercase;
            letter-spacing: 4px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            background: linear-gradient(45deg, #1a2980, #26d0ce);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .certifies {
            font-size: 20px;
            margin: 30px 0 20px 0;
            color: #333;
            font-style: italic;
        }
        
        .participant-name {
            font-size: 36px;
            font-weight: bold;
            color: #26d0ce;
            margin: 25px 0;
            text-transform: uppercase;
            letter-spacing: 3px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
            border-bottom: 2px solid #26d0ce;
            padding-bottom: 10px;
            display: inline-block;
        }
        
        .participant-id {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            font-style: italic;
        }
        
        .event-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #26d0ce;
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
            position: relative;
        }
        
        .event-section::before {
            content: '🎓';
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 0 15px;
            font-size: 24px;
        }
        
        .event-intro {
            font-size: 18px;
            margin-bottom: 15px;
            color: #333;
            font-weight: 500;
        }
        
        .event-name {
            font-size: 24px;
            font-weight: bold;
            color: #1a2980;
            margin: 20px 0;
            line-height: 1.3;
        }
        
        .event-details {
            font-size: 16px;
            line-height: 2;
            color: #555;
            margin-top: 20px;
        }
        
        .event-details strong {
            color: #1a2980;
        }
        
        .footer {
            margin-top: 50px;
            border-top: 2px solid #e9ecef;
            padding-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .verification {
            text-align: left;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }
        
        .verification-title {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .verification-code {
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: bold;
            color: #1a2980;
            background: white;
            padding: 10px 15px;
            border-radius: 5px;
            border: 2px solid #26d0ce;
            display: inline-block;
        }
        
        .verification-url {
            font-size: 10px;
            color: #666;
            margin-top: 10px;
            word-break: break-all;
        }
        
        .signature-section {
            text-align: right;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            width: 200px;
            margin: 20px 0 10px auto;
        }
        
        .signature-text {
            font-size: 14px;
            color: #666;
            font-weight: bold;
        }
        
        .date {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
            font-style: italic;
        }
        
        .decorative-elements {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            overflow: hidden;
            border-radius: 15px;
        }
        
        .decorative-elements::before,
        .decorative-elements::after {
            content: '';
            position: absolute;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(45deg, #26d0ce, #1a2980);
            opacity: 0.05;
        }
        
        .decorative-elements::before {
            top: -50px;
            left: -50px;
        }
        
        .decorative-elements::after {
            bottom: -50px;
            right: -50px;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .certificate {
                box-shadow: none;
                border: 6px solid #1a2980;
                max-width: none;
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .certificate {
                padding: 30px 20px;
            }
            
            .title {
                font-size: 28px;
                letter-spacing: 2px;
            }
            
            .participant-name {
                font-size: 24px;
                letter-spacing: 1px;
            }
            
            .footer {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .signature-section {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class='certificate'>
        <div class='decorative-elements'></div>
        
        <div class='header'>
            <div class='institution'>Universidad Distrital Francisco José de Caldas</div>
            <div class='department'>Sistema de Gestión de Proyectos y Oficina de Extensión (SGPOE)</div>
        </div>
        
        <div class='title'>Certificado de Participación</div>
        
        <div class='certifies'>Se certifica que:</div>
        
        <div class='participant-name'>$nombre_completo</div>
        <div class='participant-id'>Documento de Identidad: " . htmlspecialchars($participante['numero_identificacion']) . "</div>
        
        <div class='event-section'>
            <div class='event-intro'>Participó exitosamente en el evento:</div>
            <div class='event-name'>" . htmlspecialchars($participante['evento_nombre']) . "</div>
            <div class='event-details'>
                <strong>📅 Realizado:</strong> del $fecha_inicio al $fecha_fin<br>
                <strong>💻 Modalidad:</strong> " . ucfirst($participante['modalidad']) . "<br>
                <strong>📍 Lugar:</strong> " . htmlspecialchars($participante['lugar'] ?: 'Virtual') . "<br>
                <strong>🏛️ Entidad Organizadora:</strong> " . htmlspecialchars($participante['entidad_organizadora']) . "<br>
                <strong>⏱️ Duración:</strong> " . ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'No especificada') . "<br>
                <strong>👤 En calidad de:</strong> " . ucfirst($participante['rol']) . "
            </div>
        </div>
        
        <div class='footer'>
            <div class='verification'>
                <div class='verification-title'>🔐 Código de Verificación</div>
                <div class='verification-code'>$codigo_verificacion</div>
                <div class='verification-url'>
                    Verificar autenticidad en:<br>
                    " . PUBLIC_URL . "verificar.php?codigo=$codigo_verificacion
                </div>
            </div>
            
            <div class='signature-section'>
                <div class='signature-line'></div>
                <div class='signature-text'>Firma Digital Autorizada</div>
                <div class='date'>Bogotá D.C., $fecha_actual</div>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-imprimir al cargar (opcional)
        // window.onload = function() { window.print(); }
        
        // Función para descargar como PDF desde el navegador
        function descargarPDF() {
            window.print();
        }
        
        // Agregar botón de descarga (se oculta al imprimir)
        document.addEventListener('DOMContentLoaded', function() {
            const btnDescarga = document.createElement('button');
            btnDescarga.innerHTML = '📥 Descargar PDF';
            btnDescarga.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #26d0ce;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 5px;
                cursor: pointer;
                font-weight: bold;
                z-index: 1000;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            `;
            btnDescarga.onclick = descargarPDF;
            document.body.appendChild(btnDescarga);
            
            // Ocultar el botón al imprimir
            window.addEventListener('beforeprint', function() {
                btnDescarga.style.display = 'none';
            });
            
            window.addEventListener('afterprint', function() {
                btnDescarga.style.display = 'block';
            });
        });
    </script>
</body>
</html>";
}
?>