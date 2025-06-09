<?php
// public/consulta.php
require_once '../config/config.php';
require_once '../includes/funciones.php';

$certificados = [];
$error = '';
$busqueda_realizada = false;

if ($_POST) {
    $numero_identificacion = limpiarDatos($_POST['numero_identificacion']);
    
    if (empty($numero_identificacion)) {
        $error = 'Por favor, ingrese su número de identificación';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Buscar certificados del participante
            $stmt = $db->prepare("
                SELECT 
                    c.id,
                    c.codigo_verificacion,
                    c.fecha_generacion,
                    c.archivo_pdf,
                    c.estado,
                    p.nombres,
                    p.apellidos,
                    p.rol,
                    e.nombre as evento_nombre,
                    e.fecha_inicio,
                    e.fecha_fin,
                    e.entidad_organizadora,
                    e.modalidad,
                    e.horas_duracion
                FROM certificados c
                JOIN participantes p ON c.participante_id = p.id
                JOIN eventos e ON c.evento_id = e.id
                WHERE p.numero_identificacion = ?
                ORDER BY c.fecha_generacion DESC
            ");
            
            $stmt->execute([$numero_identificacion]);
            $certificados = $stmt->fetchAll();
            $busqueda_realizada = true;
            
            // Registrar consulta en auditoría
            registrarAuditoria('CONSULTA_PUBLICA', 'certificados', null, null, [
                'numero_identificacion' => $numero_identificacion,
                'certificados_encontrados' => count($certificados)
            ]);
            
        } catch (Exception $e) {
            $error = 'Error al realizar la consulta. Intente nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Certificados - Sistema de Certificados Digitales</title>
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
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .search-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 2rem;
        }
        
        .search-form {
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
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-search {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            white-space: nowrap;
        }
        
        .btn-search:hover {
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
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .certificates-section {
            margin-top: 2rem;
        }
        
        .certificate-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        
        .certificate-card:hover {
            transform: translateY(-5px);
        }
        
        .certificate-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .certificate-title {
            flex: 1;
        }
        
        .certificate-title h3 {
            color: #333;
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }
        
        .certificate-subtitle {
            color: #666;
            font-size: 0.9rem;
        }
        
        .certificate-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-generado {
            background-color: #d4edda;
            color: #155724;
        }
        
        .certificate-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.25rem;
        }
        
        .detail-value {
            font-weight: 600;
            color: #333;
        }
        
        .certificate-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .verification-code {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            text-align: center;
        }
        
        .verification-code strong {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            color: #667eea;
        }
        
        .no-results {
            background: white;
            border-radius: 15px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .no-results-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .no-results h3 {
            color: #333;
            margin-bottom: 1rem;
        }
        
        .no-results p {
            color: #666;
            margin-bottom: 2rem;
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
        
        .info-banner ul {
            margin-left: 1.5rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }
            
            .search-form {
                flex-direction: column;
                gap: 1rem;
            }
            
            .certificate-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .certificate-details {
                grid-template-columns: 1fr;
            }
            
            .certificate-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Consultar Certificados</h1>
            <p>Ingrese su número de identificación para ver todos sus certificados emitidos</p>
        </div>
        
        <div class="info-banner">
            <h4>💡 Información importante:</h4>
            <ul>
                <li>Utilice el mismo número de identificación con el que se registró en los eventos</li>
                <li>Los certificados están disponibles inmediatamente después de ser generados</li>
                <li>Puede descargar sus certificados en formato PDF las veces que necesite</li>
                <li>Cada certificado tiene un código único de verificación</li>
            </ul>
        </div>
        
        <div class="search-card">
            <form method="POST" class="search-form">
                <div class="form-group">
                    <label for="numero_identificacion">Número de Identificación</label>
                    <input 
                        type="text" 
                        id="numero_identificacion" 
                        name="numero_identificacion" 
                        value="<?php echo isset($numero_identificacion) ? htmlspecialchars($numero_identificacion) : ''; ?>"
                        placeholder="Ej: 12345678, CC12345678, etc."
                        required
                    >
                </div>
                <button type="submit" class="btn-search">🔍 Buscar Certificados</button>
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