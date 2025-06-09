<?php
// public/verificar.php
require_once '../config/config.php';
require_once '../includes/funciones.php';

$certificado = null;
$error = '';
$codigo_busqueda = '';

// Si viene el código por URL (desde consulta.php)
if (isset($_GET['codigo'])) {
    $codigo_busqueda = limpiarDatos($_GET['codigo']);
}

if ($_POST || $codigo_busqueda) {
    $codigo_verificacion = $codigo_busqueda ?: limpiarDatos($_POST['codigo_verificacion']);
    
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
    <title>Verificar Certificado - Sistema de Certificados Digitales</title>
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
            padding: 2rem 0;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .verification-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
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
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Courier New', monospace;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-verify {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            white-space: nowrap;
        }
        
        .btn-verify:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .verification-result {
            margin-top: 2rem;
        }
        
        .result-valid {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #28a745;
        }
        
        .result-invalid {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #dc3545;
            text-align: center;
        }
        
        .result-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .result-icon {
            font-size: 3rem;
        }
        
        .result-icon.valid {
            color: #28a745;
        }
        
        .result-icon.invalid {
            color: #dc3545;
        }
        
        .result-title {
            flex: 1;
        }
        
        .result-title h3 {
            color: #333;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .result-subtitle {
            color: #666;
            font-size: 0.9rem;
        }
        
        .certificate-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
        }
        
        .info-section h4 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .info-item:last-child {
            margin-bottom: 0;
        }
        
        .info-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .info-value {
            color: #333;
            font-weight: 600;
            text-align: right;
        }
        
        .verification-details {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
        }
        
        .verification-details h5 {
            color: #1976d2;
            margin-bottom: 0.5rem;
        }
        
        .verification-details p {
            color: #424242;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .hash-display {
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
        
        <?php if ($busqueda_realizada): ?>
            <div class="certificates-section">
                <?php if (!empty($certificados)): ?>
                    <div class="alert alert-info">
                        Se encontraron <strong><?php echo count($certificados); ?></strong> certificado(s) para la identificación <strong><?php echo htmlspecialchars($numero_identificacion); ?></strong>
                    </div>
                    
                    <?php foreach ($certificados as $cert): ?>
                        <div class="certificate-card">
                            <div class="certificate-header">
                                <div class="certificate-title">
                                    <h3><?php echo htmlspecialchars($cert['evento_nombre']); ?></h3>
                                    <div class="certificate-subtitle">
                                        <?php echo htmlspecialchars($cert['entidad_organizadora']); ?>
                                    </div>
                                </div>
                                <div class="certificate-status status-generado">
                                    ✓ Certificado Válido
                                </div>
                            </div>
                            
                            <div class="certificate-details">
                                <div class="detail-item">
                                    <div class="detail-label">Participante</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($cert['nombres'] . ' ' . $cert['apellidos']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Rol</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($cert['rol']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Fechas del Evento</div>
                                    <div class="detail-value">
                                        <?php echo formatearFecha($cert['fecha_inicio']); ?> - <?php echo formatearFecha($cert['fecha_fin']); ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Modalidad</div>
                                    <div class="detail-value"><?php echo ucfirst($cert['modalidad']); ?></div>
                                </div>
                                <?php if ($cert['horas_duracion']): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Duración</div>
                                    <div class="detail-value"><?php echo $cert['horas_duracion']; ?> horas</div>
                                </div>
                                <?php endif; ?>
                                <div class="detail-item">
                                    <div class="detail-label">Fecha de Emisión</div>
                                    <div class="detail-value"><?php echo formatearFecha($cert['fecha_generacion'], 'd/m/Y H:i'); ?></div>
                                </div>
                            </div>
                            
                            <div class="verification-code">
                                <div style="margin-bottom: 0.5rem; color: #666; font-size: 0.9rem;">Código de Verificación:</div>
                                <strong><?php echo htmlspecialchars($cert['codigo_verificacion']); ?></strong>
                            </div>
                            
                            <div class="certificate-actions">
                                <?php if ($cert['archivo_pdf']): ?>
                                    <a href="descargar.php?codigo=<?php echo urlencode($cert['codigo_verificacion']); ?>" class="btn btn-primary">
                                        📥 Descargar PDF
                                    </a>
                                <?php endif; ?>
                                <a href="verificar.php?codigo=<?php echo urlencode($cert['codigo_verificacion']); ?>" class="btn btn-secondary">
                                    ✓ Verificar Autenticidad
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                    <div class="no-results">
                        <div class="no-results-icon">📋</div>
                        <h3>No se encontraron certificados</h3>
                        <p>No hay certificados asociados al número de identificación <strong><?php echo htmlspecialchars($numero_identificacion); ?></strong></p>
                        <div style="color: #666; font-size: 0.9rem;">
                            <p><strong>Posibles causas:</strong></p>
                            <ul style="list-style: none; margin-top: 0.5rem;">
                                <li>• El número de identificación no está registrado en ningún evento</li>
                                <li>• Los certificados aún no han sido generados</li>
                                <li>• Verificque que esté usando el mismo número con el que se registró</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="back-home">
            <a href="index.php">← Volver al inicio</a>
        </div>
    </div>
    
    <script>
        // Formatear número de identificación mientras se escribe
        document.getElementById('numero_identificacion').addEventListener('input', function(e) {
            let valor = e.target.value.replace(/\s+/g, ''); // Remover espacios
            e.target.value = valor;
        });
        
        // Enfocar automáticamente el campo de búsqueda
        window.addEventListener('load', function() {
            document.getElementById('numero_identificacion').focus();
        });
    </script>
</body>
</html>