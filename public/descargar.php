<?php
// public/descargar.php - VERSIÓN CORREGIDA
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
            $ruta_archivo = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
            
            if (!file_exists($ruta_archivo)) {
                $error = 'El archivo del certificado no existe en el servidor';
                error_log("Archivo no encontrado: {$ruta_archivo}");
            } else {
                // DETECTAR TIPO DE ARCHIVO AUTOMÁTICAMENTE
                $extension = strtolower(pathinfo($certificado['archivo_pdf'], PATHINFO_EXTENSION));
                $contenido_archivo = file_get_contents($ruta_archivo);
                
                // Determinar el tipo real del archivo
                if ($extension === 'svg' || strpos($contenido_archivo, '<svg') !== false) {
                    $tipo_real = 'svg';
                    $content_type = 'image/svg+xml';
                } else {
                    $tipo_real = 'pdf';
                    $content_type = 'application/pdf';
                }
                
                // Actualizar contador de descargas
                $stmt = $db->prepare("
                    UPDATE certificados SET 
                    descargas = descargas + 1,
                    fecha_descarga = CURRENT_TIMESTAMP
                    WHERE codigo_verificacion = ?
                ");
                $stmt->execute([$codigo_verificacion]);
                
                // Registrar descarga en auditoría
                registrarAuditoria('DESCARGA', 'certificados', $certificado['certificado_id'], null, [
                    'codigo_verificacion' => $codigo_verificacion,
                    'tipo_archivo' => $tipo_real,
                    'ip_descarga' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
                
                // Generar nombre de archivo para descarga
                $nombre_participante = str_replace(' ', '_', $certificado['nombres'] . '_' . $certificado['apellidos']);
                $nombre_evento = str_replace(' ', '_', substr($certificado['evento_nombre'], 0, 30));
                $nombre_descarga = "Certificado_{$nombre_participante}_{$nombre_evento}.{$tipo_real}";
                
                // Configurar headers según el tipo de archivo
                header('Content-Type: ' . $content_type);
                header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
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
        error_log("Error en descarga: " . $e->getMessage());
    }
}

// Si hay error, mostrar página de error
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error de Descarga - Sistema de Certificados</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        }
        .error-icon { font-size: 5rem; color: #dc3545; margin-bottom: 1.5rem; }
        .error-title { color: #333; font-size: 1.8rem; margin-bottom: 1rem; font-weight: 600; }
        .error-message { color: #666; margin-bottom: 2rem; line-height: 1.6; font-size: 1.1rem; }
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
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3); }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">📄❌</div>
        <h2 class="error-title">Error de Descarga</h2>
        <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
        
        <?php if (isset($codigo_verificacion)): ?>
            <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem; margin-bottom: 2rem; font-family: 'Courier New', monospace; color: #dc3545; font-weight: 600;">
                Código consultado: <?php echo htmlspecialchars($codigo_verificacion); ?>
            </div>
        <?php endif; ?>
        
        <div>
            <a href="consulta.php" class="btn">🔍 Consultar Certificados</a>
            <a href="verificar.php" class="btn">✓ Verificar Código</a>
        </div>
    </div>
</body>
</html>