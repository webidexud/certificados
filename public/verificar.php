<?php
// public/verificar.php
require_once '../config/config.php';
require_once '../includes/funciones.php';

$certificado = null;
$error = '';
$codigo_busqueda = '';
$busqueda_realizada = false; // ← AGREGAR ESTA LÍNEA

// Si viene el código por URL (desde consulta.php)
if (isset($_GET['codigo'])) {
    $codigo_busqueda = limpiarDatos($_GET['codigo']);
}

if ($_POST || $codigo_busqueda) {
    $codigo_verificacion = $codigo_busqueda ?: limpiarDatos($_POST['codigo_verificacion']);
    $busqueda_realizada = true; // ← AGREGAR ESTA LÍNEA
    
    if (empty($codigo_verificacion)) {
        $error = 'Por favor, ingrese el código de verificación';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    c.*,
                    p.nombres,
                    p.apellidos,
                    p.numero_identificacion,
                    p.correo_electronico,
                    p.rol,
                    p.telefono,
                    p.institucion,
                    e.nombre as evento_nombre,
                    e.fecha_inicio,
                    e.fecha_fin,
                    e.entidad_organizadora,
                    e.modalidad,
                    e.lugar,
                    e.horas_duracion
                FROM certificados c
                JOIN participantes p ON c.participante_id = p.id
                JOIN eventos e ON c.evento_id = e.id
                WHERE c.codigo_verificacion = ?
            ");
            
            $stmt->execute([$codigo_verificacion]);
            $certificado = $stmt->fetch();
            
            if ($certificado) {
                // Registrar verificación en auditoría
                registrarAuditoria('VERIFICACION', 'certificados', $certificado['id'], null, [
                    'codigo_verificacion' => $codigo_verificacion,
                    'ip_consultante' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
            }
            
        } catch (Exception $e) {
            $error = 'Error al verificar el certificado. Intente nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Certificado - Sistema de Certificados</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .verification-card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .verification-form {
            display: flex;
            gap: 1rem;
            align-items: end;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-verify {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-verify:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left-color: #e53e3e;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }
        
        .certificate-result {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .result-title h2 {
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .result-title p {
            color: #718096;
            font-size: 0.9rem;
        }
        
        .result-status {
            text-align: right;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .status-valid {
            background: #d4edda;
            color: #155724;
        }
        
        .status-invalid {
            background: #f8d7da;
            color: #721c24;
        }
        
        .certificate-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .info-section h3 {
            color: #2d3748;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        
        .info-item {
            margin-bottom: 1rem;
        }
        
        .info-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: #2d3748;
            font-size: 1rem;
        }
        
        .download-section {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e1e5e9;
        }
        
        .btn-download {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            text-decoration: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            display: inline-block;
            transition: transform 0.2s;
        }
        
        .btn-download:hover {
            transform: translateY(-2px);
        }
        
        .verification-code {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            word-break: break-all;
            background: #f5f5f5;
            padding: 0.5rem;
            border-radius: 4px;
            margin-top: 0.5rem;
        }
        
        .back-home {
            text-align: center;
            margin-top: 2rem;
        }
        
        .back-home a {
            color: white;
            text-decoration: none;
            opacity: 0.9;
            transition: opacity 0.3s;
        }
        
        .back-home a:hover {
            opacity: 1;
        }
        
        .info-banner {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 2rem;
            color: white;
            backdrop-filter: blur(10px);
        }
        
        .info-banner h4 {
            margin-bottom: 0.5rem;
        }
        
        .info-banner p {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }
            
            .verification-form {
                flex-direction: column;
                gap: 1rem;
            }
            
            .result-header {
                flex-direction: column;
                text-align: center;
            }
            
            .certificate-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✓ Verificar Certificado</h1>
            <p>Confirme la autenticidad de un certificado ingresando su código de verificación</p>
        </div>
        
        <div class="info-banner">
            <h4>🔐 Verificación Segura</h4>
            <p>Cada certificado cuenta con un código único que permite verificar su autenticidad y validez. Este sistema garantiza que el certificado no ha sido alterado y fue emitido oficialmente.</p>
        </div>
        
        <div class="verification-card">
            <form method="POST" class="verification-form">
                <div class="form-group">
                    <label for="codigo_verificacion">Código de Verificación</label>
                    <input 
                        type="text" 
                        id="codigo_verificacion" 
                        name="codigo_verificacion" 
                        value="<?php echo $codigo_busqueda ? htmlspecialchars($codigo_busqueda) : (isset($codigo_verificacion) ? htmlspecialchars($codigo_verificacion) : ''); ?>"
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
                        <div class="info-item">
                            <div class="info-label">Nombre Completo</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Documento de Identidad</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['numero_identificacion']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Rol en el Evento</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['rol']); ?></div>
                        </div>
                        <?php if ($certificado['correo_electronico']): ?>
                        <div class="info-item">
                            <div class="info-label">Correo Electrónico</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['correo_electronico']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-section">
                        <h3>📅 Información del Evento</h3>
                        <div class="info-item">
                            <div class="info-label">Nombre del Evento</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['evento_nombre']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Entidad Organizadora</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['entidad_organizadora']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Fechas</div>
                            <div class="info-value">
                                <?php echo formatearFecha($certificado['fecha_inicio']); ?> al 
                                <?php echo formatearFecha($certificado['fecha_fin']); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Modalidad</div>
                            <div class="info-value"><?php echo ucfirst($certificado['modalidad']); ?></div>
                        </div>
                        <?php if ($certificado['lugar']): ?>
                        <div class="info-item">
                            <div class="info-label">Lugar</div>
                            <div class="info-value"><?php echo htmlspecialchars($certificado['lugar']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($certificado['horas_duracion']): ?>
                        <div class="info-item">
                            <div class="info-label">Duración</div>
                            <div class="info-value"><?php echo $certificado['horas_duracion']; ?> horas académicas</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="download-section">
                    <h4>📄 Descargar Certificado</h4>
                    <p style="margin-bottom: 1rem; color: #666;">Descargue una copia oficial del certificado verificado</p>
                    <a href="descargar.php?codigo=<?php echo urlencode($certificado['codigo_verificacion']); ?>" 
                       class="btn-download">
                        📥 Descargar Certificado
                    </a>
                    
                    <div class="info-item" style="margin-top: 1.5rem;">
                        <div class="info-label">Código de Verificación</div>
                        <div class="verification-code"><?php echo htmlspecialchars($certificado['codigo_verificacion']); ?></div>
                    </div>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">
                        Generado el <?php echo date('d/m/Y H:i', strtotime($certificado['fecha_generacion'])); ?>
                    </p>
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
                
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <h3>No se encontró el certificado</h3>
                    <p>El código de verificación <strong><?php echo htmlspecialchars($codigo_verificacion); ?></strong> no corresponde a ningún certificado válido.</p>
                    <div style="margin-top: 1rem; font-size: 0.9rem;">
                        <p><strong>Posibles causas:</strong></p>
                        <ul style="list-style: none; margin-top: 0.5rem;">
                            <li>• El código fue ingresado incorrectamente</li>
                            <li>• El certificado ha sido revocado o eliminado</li>
                            <li>• El código no pertenece a este sistema</li>
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
        // Formatear código mientras se escribe (solo letras y números)
        document.getElementById('codigo_verificacion').addEventListener('input', function(e) {
            let valor = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            e.target.value = valor;
        });
        
        // Enfocar automáticamente el campo de código
        window.addEventListener('load', function() {
            document.getElementById('codigo_verificacion').focus();
        });
    </script>
</body>
</html>