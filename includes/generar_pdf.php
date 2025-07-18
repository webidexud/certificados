<?php
// includes/generar_pdf.php - GENERADOR DE PDF REAL
require_once 'funciones.php';

function generarPDFReal($participante, $codigo_verificacion) {
    try {
        // Generar contenido HTML del certificado
        $html_certificado = generarHTMLCertificado($participante, $codigo_verificacion);
        
        // Convertir HTML a PDF usando una librería simple o generar PDF básico
        $pdf_content = generarPDFBasico($html_certificado, $participante, $codigo_verificacion);
        
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
        
        return [
            'success' => true,
            'nombre_archivo' => $nombre_archivo,
            'ruta_completa' => $ruta_completa,
            'tamaño' => filesize($ruta_completa),
            'tipo' => 'pdf'
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
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Certificado - $nombre_completo</title>
        <style>
            @page {
                size: A4 landscape;
                margin: 2cm;
            }
            body {
                font-family: 'Times New Roman', serif;
                margin: 0;
                padding: 40px;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                text-align: center;
                line-height: 1.6;
            }
            .certificate {
                background: white;
                border: 8px solid #1a2980;
                border-radius: 20px;
                padding: 60px 40px;
                box-shadow: 0 0 30px rgba(0,0,0,0.1);
                max-width: 100%;
                margin: 0 auto;
                position: relative;
            }
            .header {
                border-bottom: 3px solid #26d0ce;
                padding-bottom: 30px;
                margin-bottom: 40px;
            }
            .institution {
                font-size: 18px;
                font-weight: bold;
                color: #1a2980;
                margin-bottom: 10px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .department {
                font-size: 14px;
                color: #666;
                margin-bottom: 20px;
            }
            .title {
                font-size: 36px;
                font-weight: bold;
                color: #1a2980;
                margin: 30px 0;
                text-transform: uppercase;
                letter-spacing: 3px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            }
            .certifies {
                font-size: 18px;
                margin: 30px 0 20px 0;
                color: #333;
            }
            .participant-name {
                font-size: 32px;
                font-weight: bold;
                color: #26d0ce;
                margin: 20px 0;
                text-transform: uppercase;
                letter-spacing: 2px;
                text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
            }
            .participant-id {
                font-size: 16px;
                color: #666;
                margin-bottom: 30px;
            }
            .event-info {
                background: #f8f9fa;
                border: 2px solid #e9ecef;
                border-radius: 15px;
                padding: 25px;
                margin: 30px 0;
                color: #333;
            }
            .event-name {
                font-size: 22px;
                font-weight: bold;
                color: #1a2980;
                margin-bottom: 15px;
            }
            .event-details {
                font-size: 16px;
                line-height: 1.8;
            }
            .footer {
                margin-top: 40px;
                border-top: 2px solid #e9ecef;
                padding-top: 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .verification {
                text-align: left;
            }
            .verification-title {
                font-size: 12px;
                color: #666;
                margin-bottom: 5px;
                text-transform: uppercase;
            }
            .verification-code {
                font-family: 'Courier New', monospace;
                font-size: 14px;
                font-weight: bold;
                color: #1a2980;
                background: #f8f9fa;
                padding: 8px 12px;
                border-radius: 5px;
                border: 1px solid #dee2e6;
            }
            .signature {
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
            }
            .date {
                font-size: 14px;
                color: #666;
                margin-top: 20px;
            }
            .decorative-border {
                position: absolute;
                top: 15px;
                left: 15px;
                right: 15px;
                bottom: 15px;
                border: 2px solid #26d0ce;
                border-radius: 15px;
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
                    <strong>Duración:</strong> " . ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'No especificada') . "
                </div>
            </div>
            
            <div class='footer'>
                <div class='verification'>
                    <div class='verification-title'>Código de verificación</div>
                    <div class='verification-code'>$codigo_verificacion</div>
                    <div style='font-size: 12px; color: #666; margin-top: 10px;'>
                        Verificar en:<br>
                        " . PUBLIC_URL . "verificar.php?codigo=$codigo_verificacion
                    </div>
                </div>
                
                <div class='signature'>
                    <div class='signature-line'></div>
                    <div class='signature-text'>Firma Digital Autorizada</div>
                    <div class='date'>Bogotá D.C., $fecha_actual</div>
                </div>
            </div>
        </div>
    </body>
    </html>";
}

function generarPDFBasico($html_content, $participante, $codigo_verificacion) {
    // Esta función genera un PDF simple usando texto. 
    // Para un PDF real, se necesitaría una librería como TCPDF o FPDF
    
    // Por ahora, convertimos el HTML a texto plano estructurado que simule un PDF
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $fecha_actual = date('d/m/Y');
    
    // Generar un "pseudo-PDF" con formato mejorado
    $pdf_content = "%PDF-1.4
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
/Length 2000
>>
stream
BT
/F1 24 Tf
200 500 Td
(CERTIFICADO DE PARTICIPACION) Tj
0 -40 Td
/F1 12 Tf
(UNIVERSIDAD DISTRITAL FRANCISCO JOSE DE CALDAS) Tj
0 -20 Td
(SISTEMA DE GESTION DE PROYECTOS Y OFICINA DE EXTENSION) Tj
0 -60 Td
/F1 14 Tf
(Se certifica que:) Tj
0 -40 Td
/F1 18 Tf
($nombre_completo) Tj
0 -30 Td
/F1 12 Tf
(Documento: {$participante['numero_identificacion']}) Tj
0 -40 Td
(Participo en el evento:) Tj
0 -25 Td
/F1 14 Tf
({$participante['evento_nombre']}) Tj
0 -30 Td
/F1 12 Tf
(Realizado del $fecha_inicio al $fecha_fin) Tj
0 -20 Td
(Modalidad: " . ucfirst($participante['modalidad']) . ") Tj
0 -20 Td
(Duracion: " . ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas' : 'No especificada') . ") Tj
0 -60 Td
(Codigo de verificacion: $codigo_verificacion) Tj
0 -20 Td
(Fecha de emision: $fecha_actual) Tj
ET
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

    return $pdf_content;
}

// Función auxiliar para formatear fechas
function formatearFecha($fecha) {
    return date('d/m/Y', strtotime($fecha));
}
?>