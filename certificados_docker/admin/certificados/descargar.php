<?php
// admin/certificados/descargar.php - VERSIÓN SIMPLIFICADA CON SOLO 3 BOTONES
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

// Limpiar cualquier output previo
if (ob_get_level()) {
    ob_end_clean();
}

verificarAutenticacion();

$participante_id = isset($_GET['participante_id']) ? intval($_GET['participante_id']) : 0;

// Si no hay participante_id, redirigir inmediatamente
if (!$participante_id) {
    header('Location: ../participantes/listar.php');
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener certificado
    $stmt = $db->prepare("
        SELECT c.*, p.nombres, p.apellidos, p.numero_identificacion,
               e.nombre as evento_nombre
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
        // No hay certificado - mostrar página informativa
        mostrarPaginaError('Certificado no encontrado', 'Este participante aún no tiene un certificado generado.');
        exit;
    }
    
    $archivo_path = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
    
    if (!file_exists($archivo_path)) {
        mostrarPaginaError('Archivo no encontrado', 'El archivo del certificado no existe en el servidor.');
        exit;
    }
    
    // Leer contenido del archivo
    $contenido = file_get_contents($archivo_path);
    if ($contenido === false) {
        mostrarPaginaError('Error de lectura', 'No se pudo leer el contenido del certificado.');
        exit;
    }
    
    // Detectar tipo de archivo
    $extension = strtolower(pathinfo($certificado['archivo_pdf'], PATHINFO_EXTENSION));
    $es_svg = ($extension === 'svg' || strpos($contenido, '<svg') !== false);
    $es_html = ($extension === 'html' || strpos($contenido, '<!DOCTYPE html') !== false);
    
    // Mostrar página de vista previa y descarga
    mostrarPaginaCertificado($certificado, $contenido, $es_svg, $es_html);
    
} catch (Exception $e) {
    mostrarPaginaError('Error del sistema', 'Error interno: ' . $e->getMessage());
}

function mostrarPaginaError($titulo, $mensaje) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - <?php echo htmlspecialchars($titulo); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                text-align: center;
                max-width: 500px;
                width: 100%;
            }
            .error-icon {
                font-size: 4rem;
                margin-bottom: 1rem;
                color: #e53e3e;
            }
            .error-title {
                font-size: 1.5rem;
                color: #2d3748;
                margin-bottom: 1rem;
                font-weight: 600;
            }
            .error-message {
                color: #718096;
                margin-bottom: 2rem;
                line-height: 1.6;
            }
            .btn-back {
                background: #4299e1;
                color: white;
                padding: 12px 24px;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                transition: background 0.3s;
                display: inline-block;
            }
            .btn-back:hover {
                background: #3182ce;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h2 class="error-title"><?php echo htmlspecialchars($titulo); ?></h2>
            <p class="error-message"><?php echo htmlspecialchars($mensaje); ?></p>
            <a href="../participantes/listar.php" class="btn-back">← Volver a Participantes</a>
        </div>
    </body>
    </html>
    <?php
}

function mostrarPaginaCertificado($certificado, $contenido, $es_svg, $es_html) {
    global $participante_id;
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Certificado - <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f7fafc;
                line-height: 1.6;
            }
            
            .header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 1.5rem 0;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            .header-content {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .logo h1 {
                font-size: 1.5rem;
                font-weight: 600;
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
                border-radius: 6px;
                font-weight: 500;
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
            
            .certificate-card {
                background: white;
                border-radius: 15px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                overflow: hidden;
            }
            
            .certificate-header {
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
                color: white;
                padding: 2rem;
                text-align: center;
            }
            
            .certificate-title {
                font-size: 1.8rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
            }
            
            .certificate-subtitle {
                font-size: 1rem;
                opacity: 0.9;
            }
            
            .participant-info {
                background: #f7fafc;
                padding: 2rem;
                border-bottom: 1px solid #e2e8f0;
            }
            
            .info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
            }
            
            .info-item {
                display: flex;
                flex-direction: column;
            }
            
            .info-label {
                font-size: 0.875rem;
                color: #718096;
                margin-bottom: 0.25rem;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            
            .info-value {
                font-size: 1rem;
                color: #2d3748;
                font-weight: 600;
            }
            
            .verification-section {
                background: #edf2f7;
                padding: 1.5rem 2rem;
                border-bottom: 1px solid #e2e8f0;
                text-align: center;
            }
            
            .verification-code {
                font-family: 'Courier New', monospace;
                font-size: 1.25rem;
                font-weight: 700;
                color: #2b6cb0;
                background: white;
                padding: 0.75rem 1.5rem;
                border-radius: 8px;
                display: inline-block;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .certificate-preview {
                padding: 2rem;
                text-align: center;
            }
            
            .certificate-display {
                background: white;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 1rem;
                display: inline-block;
                max-width: 100%;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }
            
            .certificate-display svg {
                max-width: 100%;
                height: auto;
                display: block;
            }
            
            .pdf-placeholder {
                background: #f7fafc;
                border: 2px dashed #cbd5e0;
                color: #718096;
                padding: 3rem;
                border-radius: 10px;
                text-align: center;
            }
            
            .pdf-icon {
                font-size: 3rem;
                margin-bottom: 1rem;
                color: #e53e3e;
            }
            
            /* SOLO 3 BOTONES */
            .actions {
                background: #f7fafc;
                padding: 2rem;
                display: flex;
                gap: 1rem;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .btn {
                padding: 0.875rem 1.75rem;
                border: none;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.95rem;
            }
            
            .btn-print {
                background: #48bb78;
                color: white;
                box-shadow: 0 2px 8px rgba(72, 187, 120, 0.3);
            }
            
            .btn-print:hover {
                background: #38a169;
                transform: translateY(-1px);
                box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
            }
            
            .btn-verify {
                background: #4299e1;
                color: white;
                box-shadow: 0 2px 8px rgba(66, 153, 225, 0.3);
            }
            
            .btn-verify:hover {
                background: #3182ce;
                transform: translateY(-1px);
                box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
            }
            
            .btn-back {
                background: #718096;
                color: white;
                box-shadow: 0 2px 8px rgba(113, 128, 150, 0.3);
            }
            
            .btn-back:hover {
                background: #4a5568;
                transform: translateY(-1px);
                box-shadow: 0 4px 15px rgba(113, 128, 150, 0.4);
            }
            
            .file-type-badge {
                display: inline-block;
                background: #805ad5;
                color: white;
                padding: 0.375rem 0.875rem;
                border-radius: 20px;
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            
            @media (max-width: 768px) {
                .container { padding: 0 15px; }
                .header-content { flex-direction: column; gap: 1rem; }
                .certificate-header { padding: 1.5rem; }
                .certificate-title { font-size: 1.5rem; }
                .participant-info { padding: 1.5rem; }
                .info-grid { grid-template-columns: 1fr; }
                .actions { flex-direction: column; padding: 1.5rem; }
                .btn { width: 100%; justify-content: center; }
            }
            
            @media print {
                body { background: white; }
                .header, .actions, .participant-info, .verification-section { display: none; }
                .certificate-card { box-shadow: none; border-radius: 0; }
                .certificate-preview { padding: 0; }
                .certificate-display { border: none; box-shadow: none; padding: 0; }
            }
        </style>
    </head>
    <body>
        <header class="header">
            <div class="header-content">
                <div class="logo">
                    <h1>📜 Sistema de Certificados</h1>
                </div>
                <div class="user-info">
                    <span>Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
                    <a href="../logout.php" class="btn-logout">Cerrar Sesión</a>
                </div>
            </div>
        </header>
        
        <div class="container">
            <div class="certificate-card">
                <div class="certificate-header">
                    <div class="certificate-title">🎓 Certificado Digital</div>
                    <div class="certificate-subtitle">
                        <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?>
                    </div>
                </div>
                
                <div class="participant-info">
                    <div class="info-grid">
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
                            <div class="info-label">Tipo de archivo</div>
                            <div class="info-value">
                                <span class="file-type-badge"><?php echo $es_svg ? 'SVG' : 'PDF'; ?></span>
                                <?php echo $es_svg ? 'Gráfico vectorial' : 'Documento PDF'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Fecha de generación</div>
                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($certificado['fecha_generacion'])); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="verification-section">
                    <div style="margin-bottom: 0.5rem; color: #718096; font-size: 0.875rem; font-weight: 500;">
                        🔐 Código de Verificación
                    </div>
                    <div class="verification-code">
                        <?php echo htmlspecialchars($certificado['codigo_verificacion']); ?>
                    </div>
                </div>
                
                <div class="certificate-preview">
                    <?php if ($es_svg): ?>
                        <div class="certificate-display">
                            <?php echo $contenido; ?>
                        </div>
                    <?php elseif ($es_html): ?>
                        <div class="certificate-display" style="max-height: 600px; overflow: auto;">
                            <iframe src="?participante_id=<?php echo $participante_id; ?>&action=download" 
                                    style="width: 100%; height: 500px; border: 1px solid #ddd; border-radius: 5px;"
                                    title="Vista previa del certificado"></iframe>
                        </div>
                    <?php else: ?>
                        <div class="pdf-placeholder">
                            <div class="pdf-icon">📄</div>
                            <h3>Certificado PDF</h3>
                            <p>Este certificado está en formato PDF.<br>Use el botón de imprimir para obtener una copia.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- SOLO 3 BOTONES -->
                <div class="actions">
                    <button onclick="window.print()" class="btn btn-print">
                        🖨️ Imprimir Certificado
                    </button>
                    
                    <a href="<?php echo PUBLIC_URL; ?>verificar.php?codigo=<?php echo urlencode($certificado['codigo_verificacion']); ?>" 
                       target="_blank" class="btn btn-verify">
                        🔍 Verificar Online
                    </a>
                    
                    <a href="../participantes/listar.php" class="btn btn-back">
                        ← Volver
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>