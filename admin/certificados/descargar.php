<?php
// admin/certificados/descargar.php - DESCARGA DE CERTIFICADOS MINIMALISTA
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$participante_id = isset($_GET['participante_id']) ? intval($_GET['participante_id']) : 0;

if (!$participante_id) {
    $_SESSION['error_mensaje'] = 'ID de participante no válido';
    header('Location: ../participantes/listar.php');
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener información del certificado y participante
    $stmt = $db->prepare("
        SELECT c.*, p.nombres, p.apellidos, p.numero_identificacion, p.correo_electronico,
               e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin
        FROM certificados c
        JOIN participantes p ON c.participante_id = p.id
        JOIN eventos e ON c.evento_id = e.id
        WHERE c.participante_id = ?
        ORDER BY c.fecha_generacion DESC
        LIMIT 1
    ");
    $stmt->execute([$participante_id]);
    $certificado = $stmt->fetch();
    
    if (!$certificado) {
        $_SESSION['error_mensaje'] = 'No se encontró certificado para este participante';
        header('Location: ../participantes/listar.php');
        exit;
    }
    
    // Acción de descarga
    if (isset($_GET['action']) && $_GET['action'] === 'download') {
        $archivo_ruta = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
        
        if (!file_exists($archivo_ruta)) {
            $_SESSION['error_mensaje'] = 'Archivo de certificado no encontrado';
            header('Location: ../participantes/listar.php');
            exit;
        }
        
        // Determinar tipo de contenido
        $extension = strtolower(pathinfo($certificado['archivo_pdf'], PATHINFO_EXTENSION));
        $content_type = $extension === 'svg' ? 'image/svg+xml' : 'application/pdf';
        
        // Generar nombre de descarga
        $nombre_descarga = 'Certificado_' . 
                          str_replace(' ', '_', $certificado['nombres']) . '_' . 
                          str_replace(' ', '_', $certificado['apellidos']) . 
                          '.' . $extension;
        
        // Registrar descarga en auditoría
        registrarAuditoria('DESCARGAR_CERTIFICADO', 'certificados', $certificado['id'], null, [
            'participante_id' => $participante_id,
            'codigo_verificacion' => $certificado['codigo_verificacion']
        ]);
        
        // Enviar archivo
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
        header('Content-Length: ' . filesize($archivo_ruta));
        header('Cache-Control: private');
        header('Pragma: private');
        
        readfile($archivo_ruta);
        exit;
    }
    
} catch (Exception $e) {
    $_SESSION['error_mensaje'] = 'Error: ' . $e->getMessage();
    header('Location: ../participantes/listar.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado - Sistema de Certificados</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
            line-height: 1.5;
        }
        
        .header {
            background: #fff;
            border-bottom: 1px solid #e1e5e9;
            padding: 1rem 0;
        }
        
        .header-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .back-link {
            color: #4299e1;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid #4299e1;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .back-link:hover {
            background: #4299e1;
            color: white;
        }
        
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .certificate-card {
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
            overflow: hidden;
        }
        
        .certificate-header {
            background: #f7fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .certificate-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .certificate-subtitle {
            color: #718096;
            font-size: 0.9rem;
        }
        
        .certificate-info {
            padding: 2rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-weight: 500;
            color: #2d3748;
        }
        
        .certificate-details {
            background: #f7fafc;
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 2rem;
        }
        
        .verification-code {
            text-align: center;
            padding: 1rem;
            background: #e6fffa;
            border: 1px solid #81e6d9;
            border-radius: 6px;
            margin-bottom: 2rem;
        }
        
        .verification-code strong {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            color: #2d3748;
        }
        
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
        }
        
        .btn-success {
            background: #38a169;
            color: white;
        }
        
        .btn-success:hover {
            background: #2f855a;
        }
        
        .file-info {
            text-align: center;
            color: #718096;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .actions {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Certificado Digital</h1>
            <a href="../participantes/listar.php" class="back-link">← Volver</a>
        </div>
    </header>
    
    <div class="container">
        <div class="certificate-card">
            <div class="certificate-header">
                <div class="certificate-title">
                    Certificado de <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?>
                </div>
                <div class="certificate-subtitle">
                    <?php echo htmlspecialchars($certificado['evento_nombre']); ?>
                </div>
            </div>
            
            <div class="certificate-info">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Participante</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Identificación</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($certificado['numero_identificacion']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Evento</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($certificado['evento_nombre']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Fecha del evento</div>
                        <div class="info-value">
                            <?php echo formatearFecha($certificado['fecha_inicio']); ?> - <?php echo formatearFecha($certificado['fecha_fin']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Tipo de archivo</div>
                        <div class="info-value">
                            <?php echo strtoupper($certificado['tipo_archivo'] ?: 'PDF'); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Fecha de generación</div>
                        <div class="info-value">
                            <?php echo date('d/m/Y H:i', strtotime($certificado['fecha_generacion'])); ?>
                        </div>
                    </div>
                </div>
                
                <div class="verification-code">
                    <div style="margin-bottom: 0.5rem; color: #718096; font-size: 0.9rem;">
                        Código de verificación
                    </div>
                    <strong><?php echo htmlspecialchars($certificado['codigo_verificacion']); ?></strong>
                </div>
                
                <div class="file-info">
                    Archivo: <?php echo htmlspecialchars($certificado['archivo_pdf']); ?>
                </div>
                
                <div class="actions">
                    <a href="?participante_id=<?php echo $participante_id; ?>&action=download" class="btn btn-success">
                        Descargar Certificado
                    </a>
                    
                    <a href="<?php echo PUBLIC_URL; ?>verificar.php?codigo=<?php echo $certificado['codigo_verificacion']; ?>" 
                       target="_blank" class="btn btn-primary">
                        Verificar en línea
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>