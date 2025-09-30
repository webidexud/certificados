<?php
// admin/certificados/vista.php - Vista previa de certificados SVG
require_once '../../config/config.php';
require_once '../../includes/funciones.php';


$participante_id = isset($_GET['participante_id']) ? intval($_GET['participante_id']) : 0;

if (!$participante_id) {
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
    
    $archivo_ruta = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
    
    if (!file_exists($archivo_ruta)) {
        $_SESSION['error_mensaje'] = 'Archivo de certificado no encontrado';
        header('Location: ../participantes/listar.php');
        exit;
    }
    
    // Leer contenido y detectar tipo
    $contenido = file_get_contents($archivo_ruta);
    $extension = strtolower(pathinfo($certificado['archivo_pdf'], PATHINFO_EXTENSION));
    $es_svg = ($extension === 'svg' || strpos($contenido, '<svg') !== false);
    
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
    <title>Vista Previa - Certificado</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo h1 {
            font-size: 1.5rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 20px;
        }
        
        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px 10px 0 0;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title h2 {
            color: #495057;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .participant-info {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .certificate-container {
            background: white;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .certificate-info {
            background: #f8f9fa;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #dee2e6;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 0.25rem;
            font-weight: 500;
        }
        
        .info-value {
            font-size: 0.9rem;
            color: #495057;
            font-weight: 600;
        }
        
        .certificate-viewer {
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
        }
        
        .certificate-display {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: inline-block;
            max-width: 100%;
            overflow: auto;
        }
        
        .certificate-display svg {
            max-width: 100%;
            height: auto;
            display: block;
        }
        
        .no-preview {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 2rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .actions {
            background: white;
            padding: 1.5rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
            transform: translateY(-1px);
        }
        
        .file-type-badge {
            display: inline-block;
            background: #6f42c1;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 0 10px;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .certificate-viewer {
                padding: 1rem;
            }
            
            .certificate-info {
                grid-template-columns: 1fr;
            }
        }
        
        @media print {
            body {
                background: white;
            }
            
            .header, .actions, .certificate-info, .page-header {
                display: none;
            }
            
            .certificate-container {
                box-shadow: none;
                border-radius: 0;
            }
            
            .certificate-viewer {
                padding: 0;
                background: white;
            }
            
            .certificate-display {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <h1>Sistema de Certificados</h1>
            </div>
            <div class="user-info">
                <span>Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
                <a href="../logout.php" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="certificate-container">
            <div class="page-header">
                <div>
                    <div class="page-title">
                        <h2>📜 Vista Previa del Certificado</h2>
                    </div>
                    <div class="participant-info">
                        <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?>
                    </div>
                </div>
                <div>
                    <span class="file-type-badge"><?php echo $es_svg ? 'SVG' : 'PDF'; ?></span>
                </div>
            </div>
            
            <div class="certificate-info">
                <div class="info-item">
                    <div class="info-label">Participante</div>
                    <div class="info-value"><?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Documento</div>
                    <div class="info-value"><?php echo htmlspecialchars($certificado['numero_identificacion']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Evento</div>
                    <div class="info-value"><?php echo htmlspecialchars($certificado['evento_nombre']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Código de verificación</div>
                    <div class="info-value" style="font-family: 'Courier New', monospace;"><?php echo htmlspecialchars($certificado['codigo_verificacion']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Fecha de generación</div>
                    <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($certificado['fecha_generacion'])); ?></div>
                </div>
            </div>
            
            <div class="certificate-viewer">
                <?php if ($es_svg): ?>
                    <div class="certificate-display">
                        <?php echo $contenido; ?>
                    </div>
                <?php else: ?>
                    <div class="no-preview">
                        <h4>📄 Certificado PDF</h4>
                        <p>Los certificados PDF se pueden descargar directamente. Use el botón de descarga para obtener el archivo.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="actions">
                <a href="descargar.php?participante_id=<?php echo $participante_id; ?>" class="btn btn-success" target="_blank">
                    📥 Descargar Certificado
                </a>
                
                <a href="<?php echo PUBLIC_URL; ?>verificar.php?codigo=<?php echo $certificado['codigo_verificacion']; ?>" 
                   target="_blank" class="btn btn-primary">
                    🔍 Verificar Online
                </a>
                
                <?php if ($es_svg): ?>
                <button onclick="window.print()" class="btn btn-info">
                    🖨️ Imprimir
                </button>
                <?php endif; ?>
                
                <a href="../participantes/listar.php" class="btn btn-secondary">
                    ← Volver a Participantes
                </a>
            </div>
        </div>
    </div>
</body>
</html>