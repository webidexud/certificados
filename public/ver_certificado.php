<?php
// public/ver_certificado.php - Optimizado para impresión sin márgenes
require_once '../config/config.php';
require_once '../includes/funciones.php';

$error = '';

if (!isset($_GET['codigo'])) {
    $error = 'Código de verificación no proporcionado';
} else {
    $codigo_verificacion = limpiarDatos($_GET['codigo']);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Verificar que el certificado existe y obtener información
        $stmt = $db->prepare("
            SELECT 
                c.archivo_pdf,
                c.tipo_archivo,
                c.dimensiones,
                c.id as certificado_id,
                p.nombres,
                p.apellidos,
                e.nombre as evento_nombre
            FROM certificados c
            JOIN participantes p ON c.participante_id = p.id
            JOIN eventos e ON c.evento_id = e.id
            WHERE c.codigo_verificacion = ?
        ");
        
        $stmt->execute([$codigo_verificacion]);
        $certificado = $stmt->fetch();
        
        if (!$certificado) {
            $error = 'Certificado no encontrado';
        } elseif (empty($certificado['archivo_pdf'])) {
            $error = 'El archivo del certificado no está disponible';
        } else {
            $tipo_archivo = $certificado['tipo_archivo'] ?: 'pdf';
            $ruta_archivo = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
            
            if (!file_exists($ruta_archivo)) {
                $error = 'El archivo del certificado no existe en el servidor';
            } else {
                // Registrar visualización en auditoría
                registrarAuditoria('VISUALIZACION', 'certificados', $certificado['certificado_id'], null, [
                    'codigo_verificacion' => $codigo_verificacion,
                    'tipo_archivo' => $tipo_archivo,
                    'ip_visualizacion' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
                
                // Si es SVG o HTML, mostrar con estilos optimizados para impresión
                if ($tipo_archivo === 'svg' || $tipo_archivo === 'html') {
                    $contenido = file_get_contents($ruta_archivo);
                    
                    // Mostrar contenido optimizado para impresión
                    ?>
                    <!DOCTYPE html>
                    <html lang="es">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Certificado - <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></title>
                        <style>
                            @page {
                                size: A4 landscape;
                                margin: 0mm !important;
                                padding: 0mm !important;
                            }
                            
                            * {
                                margin: 0 !important;
                                padding: 0 !important;
                                box-sizing: border-box;
                            }
                            
                            html, body {
                                margin: 0 !important;
                                padding: 0 !important;
                                background: white !important;
                                font-family: Arial, sans-serif;
                                width: 100% !important;
                                height: 100% !important;
                                overflow: hidden;
                            }
                            
                            .certificate-container {
                                width: 100% !important;
                                height: 100vh !important;
                                display: flex;
                                justify-content: center;
                                align-items: center;
                                background: white !important;
                                margin: 0 !important;
                                padding: 0 !important;
                            }
                            
                            .certificate-display {
                                width: 100% !important;
                                height: 100% !important;
                                margin: 0 !important;
                                padding: 0 !important;
                                border: none !important;
                                box-shadow: none !important;
                                background: white !important;
                            }
                            
                            .certificate-display svg {
                                width: 100% !important;
                                height: 100% !important;
                                margin: 0 !important;
                                padding: 0 !important;
                                display: block;
                            }
                            
                            /* Estilos específicos para HTML */
                            .certificate-display iframe {
                                width: 100% !important;
                                height: 100% !important;
                                border: none !important;
                                margin: 0 !important;
                                padding: 0 !important;
                            }
                            
                            @media print {
                                html, body {
                                    margin: 0mm !important;
                                    padding: 0mm !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                    background: white !important;
                                }
                                
                                .certificate-container {
                                    margin: 0mm !important;
                                    padding: 0mm !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                }
                                
                                .certificate-display {
                                    margin: 0mm !important;
                                    padding: 0mm !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                }
                                
                                .certificate-display svg {
                                    margin: 0mm !important;
                                    padding: 0mm !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="certificate-container">
                            <div class="certificate-display">
                                <?php 
                                if ($tipo_archivo === 'svg') {
                                    echo $contenido;
                                } elseif ($tipo_archivo === 'html') {
                                    // Para HTML, mostrar el contenido directamente
                                    echo $contenido;
                                }
                                ?>
                            </div>
                        </div>
                    </body>
                    </html>
                    <?php
                    exit;
                } else {
                    // Es un PDF, enviarlo directamente al navegador para visualización
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="certificado_' . $codigo_verificacion . '.pdf"');
                    header('Content-Length: ' . filesize($ruta_archivo));
                    header('Cache-Control: private');
                    header('Pragma: private');
                    
                    // Enviar archivo PDF
                    readfile($ruta_archivo);
                    exit;
                }
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error al procesar la visualización: ' . $e->getMessage();
    }
}

// Si hay error, mostrar página de error optimizada
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Visualización de Certificado</title>
    <style>
        @page {
            size: A4;
            margin: 0mm;
        }
        
        body {
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .error-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
        }
        
        .error-icon {
            font-size: 3rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        
        .error-title {
            color: #dc3545;
            font-size: 1.5rem;
            margin-bottom: 15px;
            font-weight: bold;
        }
        
        .error-message {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .back-btn {
            display: inline-block;
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .back-btn:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h2 class="error-title">Error al cargar el certificado</h2>
        <div class="error-message">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <a href="javascript:history.back()" class="back-btn">← Regresar</a>
    </div>
</body>
</html>