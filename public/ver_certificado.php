<?php
// public/ver_certificado.php - Visualizador de certificados en PDF
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
                
                // Si es SVG, convertir a visualización PDF o mostrar directamente
                if ($tipo_archivo === 'svg') {
                    // Leer el contenido SVG
                    $contenido_svg = file_get_contents($ruta_archivo);
                    
                    // Mostrar SVG en una página HTML responsive
                    ?>
                    <!DOCTYPE html>
                    <html lang="es">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Certificado - <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></title>
                        <style>
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
                            .certificate-container {
                                background: white;
                                padding: 20px;
                                border-radius: 8px;
                                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                                max-width: 100%;
                                text-align: center;
                            }
                            .certificate-svg {
                                max-width: 100%;
                                height: auto;
                                border: 1px solid #ddd;
                                border-radius: 4px;
                            }
                            .certificate-info {
                                margin-top: 15px;
                                padding: 10px;
                                background: #e3f2fd;
                                border-radius: 4px;
                                font-size: 0.9rem;
                                color: #1565c0;
                            }
                            .download-btn {
                                margin-top: 15px;
                                display: inline-block;
                                background: #4CAF50;
                                color: white;
                                padding: 10px 20px;
                                text-decoration: none;
                                border-radius: 5px;
                                font-weight: bold;
                            }
                            .download-btn:hover {
                                background: #45a049;
                            }
                            @media print {
                                body { background: white; padding: 0; }
                                .certificate-container { box-shadow: none; padding: 0; }
                                .certificate-info, .download-btn { display: none; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="certificate-container">
                            <div class="certificate-svg">
                                <?php echo $contenido_svg; ?>
                            </div>
                            <div class="certificate-info">
                                <strong>Certificado para:</strong> <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?><br>
                                <strong>Evento:</strong> <?php echo htmlspecialchars($certificado['evento_nombre']); ?><br>
                                <strong>Código:</strong> <?php echo htmlspecialchars($codigo_verificacion); ?>
                            </div>
                            <a href="../public/descargar.php?codigo=<?php echo urlencode($codigo_verificacion); ?>" 
                               class="download-btn" target="_blank">
                                📥 Descargar Certificado
                            </a>
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

// Si hay error, mostrar página de error
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Visualización de Certificado</title>
    <style>
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