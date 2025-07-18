<?php
// public/verificar.php - MODIFICADO CON BOTÓN DE IMPRIMIR
require_once '../config/config.php';
require_once '../includes/funciones.php';

$busqueda_realizada = false;
$certificado = null;
$error = '';
$codigo_busqueda = '';

// Procesar búsqueda
if ($_GET || $_POST) {
    $codigo_busqueda = isset($_GET['codigo']) ? limpiarDatos($_GET['codigo']) : 
                      (isset($_POST['codigo_verificacion']) ? limpiarDatos($_POST['codigo_verificacion']) : '');
    
    if (!empty($codigo_busqueda)) {
        $busqueda_realizada = true;
        
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    c.*, 
                    p.nombres, 
                    p.apellidos,
                    p.numero_identificacion,
                    p.rol,
                    p.telefono,
                    p.institucion,
                    e.nombre as evento_nombre,
                    e.fecha_inicio,
                    e.fecha_fin,
                    e.modalidad,
                    e.entidad_organizadora,
                    e.lugar,
                    e.horas_duracion
                FROM certificados c
                JOIN participantes p ON c.participante_id = p.id
                JOIN eventos e ON c.evento_id = e.id
                WHERE c.codigo_verificacion = ?
            ");
            
            $stmt->execute([$codigo_busqueda]);
            $certificado = $stmt->fetch();
            
            if ($certificado) {
                // Registrar auditoría de verificación
                registrarAuditoria('VERIFICACION', 'certificados', $certificado['id'], null, [
                    'codigo_verificacion' => $codigo_busqueda,
                    'ip_consulta' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
            }
            
        } catch (Exception $e) {
            $error = 'Error al verificar el certificado: ' . $e->getMessage();
        }
    }
}

// Determinar si el código fue pasado por URL
$codigo_verificacion = isset($_GET['codigo']) ? $_GET['codigo'] : '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Certificado - Sistema de Certificados</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 0 auto; }
        .header {
            background: rgba(255, 255, 255, 0.95);
            padding: 2rem;
            border-radius: 20px 20px 0 0;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        .header h1 { color: #333; margin-bottom: 0.5rem; font-size: 2.5rem; font-weight: 700; }
        .header p { color: #666; font-size: 1.1rem; }
        .search-container {
            background: white;
            padding: 2rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        .search-form { display: flex; gap: 1rem; align-items: end; }
        .form-group { flex: 1; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; }
        .form-group input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .form-group input:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .btn-verify {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .btn-verify:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3); }
        .alert { padding: 1rem; margin-bottom: 1rem; border-radius: 10px; }
        .alert-error { background: #fee; border: 1px solid #fcc; color: #c33; }
        .certificate-result {
            background: white;
            border-radius: 0 0 20px 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .result-header {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .result-title h2 { font-size: 2rem; margin-bottom: 0.5rem; }
        .result-title p { opacity: 0.9; }
        .status-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }
        .status-valid { background: rgba(255, 255, 255, 0.2); }
        .status-invalid { background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); }
        .certificate-info { padding: 2rem; }
        .info-section { margin-bottom: 1.5rem; }
        .info-section h3 { 
            color: #333; 
            margin-bottom: 0.75rem; 
            font-size: 1.1rem; 
            border-bottom: 1px solid #e2e8f0; 
            padding-bottom: 0.5rem; 
        }
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .info-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-list li:last-child {
            border-bottom: none;
        }
        .info-label { 
            font-size: 0.9rem; 
            font-weight: 500; 
            color: #64748b; 
            min-width: 120px;
        }
        .info-value { 
            font-size: 0.95rem; 
            color: #1e293b; 
            font-weight: 500;
            text-align: right;
            flex: 1;
            margin-left: 1rem;
        }
        .verification-code { font-family: 'Courier New', monospace; background: #e2e8f0; padding: 0.75rem; border-radius: 5px; font-size: 1.1rem; font-weight: bold; color: #333; }
        .actions-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            margin-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        /* ESTILOS PARA EL BOTÓN */
        .btn-print {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }
        .btn-print:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 12px rgba(72, 187, 120, 0.3); 
        }
        
        .no-results {
            background: white;
            border-radius: 0 0 20px 20px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .no-results-icon { font-size: 5rem; margin-bottom: 1rem; }
        .back-home { text-align: center; margin-top: 2rem; }
        .back-home a { color: white; text-decoration: none; font-weight: 600; opacity: 0.8; transition: opacity 0.3s; }
        .back-home a:hover { opacity: 1; }
        @media (max-width: 768px) {
            .search-form { flex-direction: column; }
            .result-header { flex-direction: column; text-align: center; gap: 1rem; }
            .info-list li {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            .info-value {
                text-align: left;
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Verificar Certificado</h1>
            <p>Ingrese el código de verificación para validar la autenticidad del certificado</p>
        </div>
        
        <div class="search-container">
            <form method="POST" class="search-form">
                <div class="form-group">
                    <label for="codigo_verificacion">Código de Verificación</label>
                    <input 
                        type="text" 
                        id="codigo_verificacion" 
                        name="codigo_verificacion"
                        value="<?php echo $busqueda_realizada ? htmlspecialchars($codigo_busqueda) : (isset($codigo_verificacion) ? htmlspecialchars($codigo_verificacion) : ''); ?>"
                        placeholder="Ej: ABC123XYZ456"
                        required
                        maxlength="20"
                    >
                </div>
                <button type="submit" class="btn-verify">✓ Verificar</button>
            </form>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($busqueda_realizada && $certificado): ?>
            <div class="certificate-result">
                <div class="result-header">
                    <div class="result-title">
                        <h2>Certificado Verificado</h2>
                        <p>El certificado es válido y auténtico</p>
                    </div>
                    <div class="result-status">
                        <span class="status-badge status-valid">✓ Válido</span>
                    </div>
                </div>
                
                <div class="certificate-info">
                    <div class="info-section">
                        <h3>👤 Información del Participante</h3>
                        <ul class="info-list">
                            <li>
                                <span class="info-label">Nombre Completo</span>
                                <span class="info-value"><?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Documento</span>
                                <span class="info-value"><?php echo htmlspecialchars($certificado['numero_identificacion']); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Rol</span>
                                <span class="info-value"><?php echo htmlspecialchars(ucfirst($certificado['rol'])); ?></span>
                            </li>
                            <?php if ($certificado['institucion']): ?>
                            <li>
                                <span class="info-label">Institución</span>
                                <span class="info-value"><?php echo htmlspecialchars($certificado['institucion']); ?></span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="info-section">
                        <h3>🎓 Información del Evento</h3>
                        <ul class="info-list">
                            <li>
                                <span class="info-label">Evento</span>
                                <span class="info-value"><?php echo htmlspecialchars($certificado['evento_nombre']); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Fechas</span>
                                <span class="info-value"><?php echo formatearFecha($certificado['fecha_inicio']) . ' al ' . formatearFecha($certificado['fecha_fin']); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Modalidad</span>
                                <span class="info-value"><?php echo htmlspecialchars(ucfirst($certificado['modalidad'])); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Duración</span>
                                <span class="info-value"><?php echo $certificado['horas_duracion'] ? $certificado['horas_duracion'] . ' horas' : 'No especificada'; ?></span>
                            </li>
                            <li>
                                <span class="info-label">Organiza</span>
                                <span class="info-value"><?php echo htmlspecialchars($certificado['entidad_organizadora']); ?></span>
                            </li>
                            <?php if ($certificado['lugar']): ?>
                            <li>
                                <span class="info-label">Lugar</span>
                                <span class="info-value"><?php echo htmlspecialchars($certificado['lugar']); ?></span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="info-section">
                        <h3>📄 Información del Certificado</h3>
                        <ul class="info-list">
                            <li>
                                <span class="info-label">Generado</span>
                                <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($certificado['fecha_generacion'])); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Tipo</span>
                                <span class="info-value"><?php echo strtoupper($certificado['tipo_archivo'] ?: 'PDF'); ?></span>
                            </li>
                            <li>
                                <span class="info-label">Descargas</span>
                                <span class="info-value"><?php echo $certificado['descargas'] ?: 0; ?> vez(es)</span>
                            </li>
                        </ul>
                    </div>
                </div>
            
                <div class="actions-section">
                    <?php 
                    // Detectar si es imprimible (SVG o HTML)
                    $es_imprimible = (isset($certificado['tipo_archivo']) && in_array($certificado['tipo_archivo'], ['svg', 'html'])) || 
                                   (strpos($certificado['archivo_pdf'], '.svg') !== false) || 
                                   (strpos($certificado['archivo_pdf'], '.html') !== false);
                    ?>
                    
                    <?php if ($es_imprimible): ?>
                        <button onclick="imprimirCertificado('<?php echo urlencode($certificado['codigo_verificacion']); ?>')" 
                                class="btn-print">
                            🖨️ Imprimir Certificado
                        </button>
                    <?php endif; ?>
                    
                    <div class="info-item" style="margin-top: 1rem; display: inline-block; background: white; padding: 0.75rem 1rem; border-radius: 5px;">
                        <div class="info-label" style="margin-bottom: 0.25rem;">Código de Verificación</div>
                        <div class="verification-code" style="font-size: 0.9rem; background: #e2e8f0; padding: 0.5rem; border-radius: 3px;"><?php echo htmlspecialchars($certificado['codigo_verificacion']); ?></div>
                    </div>
                </div>
            </div>
        <?php elseif ($busqueda_realizada && !$certificado): ?>
            <div class="certificate-result">
                <div class="result-header">
                    <div class="result-title">
                        <h2>Certificado No Encontrado</h2>
                        <p>No se encontró ningún certificado con el código proporcionado</p>
                    </div>
                    <div class="result-status">
                        <span class="status-badge status-invalid">❌ No Válido</span>
                    </div>
                </div>
                
                <div class="no-results">
                    <div class="no-results-icon">📋</div>
                    <h3>Código No Válido</h3>
                    <p>El código de verificación <strong><?php echo htmlspecialchars($codigo_busqueda); ?></strong> no existe en nuestro sistema</p>
                    <div style="color: #666; font-size: 0.9rem; margin-top: 1rem;">
                        <p><strong>Posibles causas:</strong></p>
                        <ul style="list-style: none; margin-top: 0.5rem;">
                            <li>• El código fue escrito incorrectamente</li>
                            <li>• El certificado ha sido eliminado del sistema</li>
                            <li>• El código ha expirado o no es válido</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="back-home">
            <a href="index.php">← Volver al inicio</a>
        </div>
    </div>
    
    <script>
        // Formatear código de verificación mientras se escribe
        document.getElementById('codigo_verificacion').addEventListener('input', function(e) {
            let valor = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            e.target.value = valor;
        });
        
        // Enfocar automáticamente el campo si no hay código en la URL
        window.addEventListener('load', function() {
            if (!document.getElementById('codigo_verificacion').value) {
                document.getElementById('codigo_verificacion').focus();
            }
        });
        
        // FUNCIÓN PARA IMPRIMIR CERTIFICADO (IGUAL QUE EN CONSULTA.PHP)
        function imprimirCertificado(codigoVerificacion) {
            // Crear URL para obtener el contenido del certificado
            const urlCertificado = `ver_certificado.php?codigo=${codigoVerificacion}`;
            
            // Crear ventana nueva para impresión
            const ventana = window.open('', '_blank', 'width=1200,height=900');
            
            // Crear contenido HTML para impresión
            ventana.document.write(`
                <html>
                <head>
                    <title>Certificado - ${codigoVerificacion}</title>
                    <style>
                        body { 
                            margin: 0; 
                            padding: 20px; 
                            background: white; 
                            font-family: Arial, sans-serif;
                        }
                        .loading {
                            text-align: center;
                            padding: 50px;
                            font-size: 18px;
                            color: #666;
                        }
                        iframe {
                            width: 100%;
                            height: 600px;
                            border: none;
                        }
                        @media print {
                            body { margin: 0; padding: 0; }
                            .loading { display: none; }
                            iframe { height: 100vh; }
                        }
                    </style>
                </head>
                <body>
                    <div class="loading">Cargando certificado...</div>
                    <iframe src="${urlCertificado}" onload="this.style.display='block'; document.querySelector('.loading').style.display='none'; setTimeout(() => window.print(), 1000);"></iframe>
                </body>
                </html>
            `);
            ventana.document.close();
        }
    </script>
</body>
</html>