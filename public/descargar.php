<?php
// public/descargar.php - VERSIÓN MEJORADA PARA SVG
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
                // Actualizar contador de descargas
                $stmt = $db->prepare("
                    UPDATE certificados SET 
                    descargas = descargas + 1,
                    fecha_descarga = CURRENT_TIMESTAMP,
                    estado = 'descargado'
                    WHERE codigo_verificacion = ?
                ");
                $stmt->execute([$codigo_verificacion]);
                
                // Registrar descarga en auditoría
                registrarAuditoria('DESCARGA', 'certificados', $certificado['certificado_id'], null, [
                    'codigo_verificacion' => $codigo_verificacion,
                    'tipo_archivo' => $tipo_archivo,
                    'ip_descarga' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
                
                // Generar nombre de archivo para descarga
                $nombre_participante = str_replace(' ', '_', $certificado['nombres'] . '_' . $certificado['apellidos']);
                $nombre_evento = str_replace(' ', '_', substr($certificado['evento_nombre'], 0, 30));
                $extension = $tipo_archivo === 'svg' ? 'svg' : 'pdf';
                $nombre_descarga = "Certificado_{$nombre_participante}_{$nombre_evento}.{$extension}";
                
                // Configurar headers según el tipo de archivo
                if ($tipo_archivo === 'svg') {
                    header('Content-Type: image/svg+xml');
                    header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
                } else {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
                }
                
                header('Content-Length: ' . filesize($ruta_archivo));
                header('Cache-Control: private');
                header('Pragma: private');
                
                // Enviar archivo
                readfile($ruta_archivo);
                exit;
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error al procesar la descarga: ' . $e->getMessage();
    }
}

// Si hay error, mostrar página de error mejorada
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error de Descarga - Sistema de Certificados</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            background: white;
            padding: 3rem;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 500px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .error-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .error-icon {
            font-size: 5rem;
            color: #dc3545;
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .error-title {
            color: #333;
            font-size: 1.8rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }
        
        .error-message {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
            font-size: 1.1rem;
        }
        
        .error-code {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
            font-family: 'Courier New', monospace;
            color: #dc3545;
            font-weight: 600;
        }
        
        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            margin: 0.5rem;
            font-size: 1rem;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            box-shadow: 0 10px 30px rgba(108, 117, 125, 0.3);
        }
        
        .help-text {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #dee2e6;
            color: #666;
            font-size: 0.9rem;
        }
        
        .help-text h4 {
            color: #333;
            margin-bottom: 1rem;
        }
        
        .help-text ul {
            text-align: left;
            margin-left: 1.5rem;
        }
        
        .help-text li {
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">📄❌</div>
        <h2 class="error-title">Error de Descarga</h2>
        <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
        
        <?php if (isset($codigo_verificacion)): ?>
            <div class="error-code">
                Código consultado: <?php echo htmlspecialchars($codigo_verificacion); ?>
            </div>
        <?php endif; ?>
        
        <div>
            <a href="consulta.php" class="btn">🔍 Consultar Certificados</a>
            <a href="verificar.php" class="btn btn-secondary">✓ Verificar Código</a>
        </div>
        
        <div class="help-text">
            <h4>💡 ¿Necesita ayuda?</h4>
            <ul>
                <li>Verifique que el código de verificación sea correcto</li>
                <li>Asegúrese de que el certificado haya sido generado</li>
                <li>Consulte sus certificados usando su número de identificación</li>
                <li>Contacte al organizador del evento si el problema persiste</li>
            </ul>
        </div>
    </div>
</body>
</html>