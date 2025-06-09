<?php
// public/descargar.php
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
            $error = 'El archivo PDF del certificado no está disponible';
        } else {
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
                    'ip_descarga' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
                
                // Generar nombre de archivo para descarga
                $nombre_participante = str_replace(' ', '_', $certificado['nombres'] . '_' . $certificado['apellidos']);
                $nombre_evento = str_replace(' ', '_', substr($certificado['evento_nombre'], 0, 30));
                $nombre_descarga = "Certificado_{$nombre_participante}_{$nombre_evento}.pdf";
                
                // Configurar headers para descarga
                header('Content-Type: application/pdf');
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
        }
        
        .error-container {
            background: white;
            padding: 3rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 500px;
            margin: 0 20px;
        }
        
        .error-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 1rem;
        }
        
        .error-title {
            color: #333;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .error-message {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">📄</div>
        <h2 class="error-title">Error de Descarga</h2>
        <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
        <a href="consulta.php" class="btn">🔍 Consultar Certificados</a>
    </div>
</body>
</html>