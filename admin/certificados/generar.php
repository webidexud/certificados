<?php
// admin/certificados/generar.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$error = '';
$success = '';
$participante_individual = null;

// Si viene un participante específico por URL
if (isset($_GET['participante_id'])) {
    $participante_id = intval($_GET['participante_id']);
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT p.*, e.nombre as evento_nombre 
            FROM participantes p 
            JOIN eventos e ON p.evento_id = e.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$participante_id]);
        $participante_individual = $stmt->fetch();
    } catch (Exception $e) {
        $error = "Error al cargar el participante: " . $e->getMessage();
    }
}

// Obtener lista de eventos para el filtro
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, nombre, fecha_inicio, fecha_fin FROM eventos ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

if ($_POST) {
    $accion = $_POST['accion'];
    $evento_id = intval($_POST['evento_id']);
    
    if (empty($evento_id)) {
        $error = 'Debe seleccionar un evento';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            if ($accion === 'generar_individual' && isset($_POST['participante_id'])) {
                // Generar certificado individual
                $participante_id = intval($_POST['participante_id']);
                $resultado = generarCertificadoIndividual($participante_id);
                
                if ($resultado['success']) {
                    $success = 'Certificado generado exitosamente para ' . $resultado['participante'];
                } else {
                    $error = $resultado['error'];
                }
                
            } elseif ($accion === 'generar_masivo') {
                // Generar certificados masivos para el evento
                $resultado = generarCertificadosMasivos($evento_id);
                
                if ($resultado['success']) {
                    $success = "Generación masiva completada: {$resultado['generados']} certificados generados, {$resultado['errores']} errores";
                } else {
                    $error = $resultado['error'];
                }
            }
            
        } catch (Exception $e) {
            $error = 'Error durante la generación: ' . $e->getMessage();
        }
    }
}

// Función para generar certificado individual
function generarCertificadoIndividual($participante_id) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Obtener datos del participante y evento
        $stmt = $db->prepare("
            SELECT 
                p.*,
                e.nombre as evento_nombre,
                e.fecha_inicio,
                e.fecha_fin,
                e.entidad_organizadora,
                e.modalidad,
                e.lugar,
                e.horas_duracion
            FROM participantes p
            JOIN eventos e ON p.evento_id = e.id
            WHERE p.id = ?
        ");
        $stmt->execute([$participante_id]);
        $participante = $stmt->fetch();
        
        if (!$participante) {
            return ['success' => false, 'error' => 'Participante no encontrado'];
        }
        
        // Verificar si ya existe certificado
        $stmt = $db->prepare("SELECT id FROM certificados WHERE participante_id = ?");
        $stmt->execute([$participante_id]);
        $certificado_existe = $stmt->fetch();
        
        if ($certificado_existe) {
            return ['success' => false, 'error' => 'El participante ya tiene un certificado generado'];
        }
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Crear hash de validación
        $hash_data = $participante['numero_identificacion'] . $participante['nombres'] . $participante['apellidos'] . 
                    $participante['evento_nombre'] . $codigo_verificacion . date('Y-m-d');
        $hash_validacion = hash('sha256', $hash_data);
        
        // Generar nombre de archivo PDF
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
        
        // Aquí iría la generación real del PDF
        // Por ahora, creamos un archivo temporal
        $contenido_pdf = generarPDFCertificado($participante, $codigo_verificacion);
        $ruta_pdf = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        file_put_contents($ruta_pdf, $contenido_pdf);
        
        // Insertar registro en la base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $participante_id,
            $participante['evento_id'],
            $codigo_verificacion,
            $nombre_archivo,
            $hash_validacion
        ]);
        
        // Registrar en auditoría
        registrarAuditoria('GENERAR_CERTIFICADO', 'certificados', $db->lastInsertId(), null, [
            'participante_id' => $participante_id,
            'codigo_verificacion' => $codigo_verificacion
        ]);
        
        return [
            'success' => true,
            'participante' => $participante['nombres'] . ' ' . $participante['apellidos'],
            'codigo' => $codigo_verificacion
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función para generar certificados masivos
function generarCertificadosMasivos($evento_id) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Obtener participantes sin certificado
        $stmt = $db->prepare("
            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion
            FROM participantes p
            JOIN eventos e ON p.evento_id = e.id
            LEFT JOIN certificados c ON p.id = c.participante_id
            WHERE p.evento_id = ? AND c.id IS NULL
        ");
        $stmt->execute([$evento_id]);
        $participantes = $stmt->fetchAll();
        
        if (empty($participantes)) {
            return ['success' => false, 'error' => 'No hay participantes sin certificado en este evento'];
        }
        
        $generados = 0;
        $errores = 0;
        
        foreach ($participantes as $participante) {
            $resultado = generarCertificadoIndividual($participante['id']);
            if ($resultado['success']) {
                $generados++;
            } else {
                $errores++;
            }
        }
        
        return [
            'success' => true,
            'generados' => $generados,
            'errores' => $errores
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función temporal para generar PDF (simplificada)
function generarPDFCertificado($participante, $codigo_verificacion) {
    // Esta es una implementación simplificada
    // En producción, usarías una librería como TCPDF o DomPDF
    
    $contenido = "%PDF-1.4\n";
    $contenido .= "1 0 obj\n";
    $contenido .= "<<\n";
    $contenido .= "/Type /Catalog\n";
    $contenido .= "/Pages 2 0 R\n";
    $contenido .= ">>\n";
    $contenido .= "endobj\n\n";
    
    $contenido .= "2 0 obj\n";
    $contenido .= "<<\n";
    $contenido .= "/Type /Pages\n";
    $contenido .= "/Kids [3 0 R]\n";
    $contenido .= "/Count 1\n";
    $contenido .= ">>\n";
    $contenido .= "endobj\n\n";
    
    $contenido .= "3 0 obj\n";
    $contenido .= "<<\n";
    $contenido .= "/Type /Page\n";
    $contenido .= "/Parent 2 0 R\n";
    $contenido .= "/MediaBox [0 0 612 792]\n";
    $contenido .= "/Contents 4 0 R\n";
    $contenido .= "/Resources <<\n";
    $contenido .= "/Font <<\n";
    $contenido .= "/F1 5 0 R\n";
    $contenido .= ">>\n";
    $contenido .= ">>\n";
    $contenido .= ">>\n";
    $contenido .= "endobj\n\n";
    
    $texto_certificado = "CERTIFICADO DE PARTICIPACION\\n\\n";
    $texto_certificado .= "Se certifica que\\n\\n";
    $texto_certificado .= strtoupper($participante['nombres'] . ' ' . $participante['apellidos']) . "\\n\\n";
    $texto_certificado .= "participo en el evento\\n\\n";
    $texto_certificado .= strtoupper($participante['evento_nombre']) . "\\n\\n";
    $texto_certificado .= "organizado por " . $participante['entidad_organizadora'] . "\\n";
    $texto_certificado .= "del " . formatearFecha($participante['fecha_inicio']) . " al " . formatearFecha($participante['fecha_fin']) . "\\n\\n";
    $texto_certificado .= "Rol: " . $participante['rol'] . "\\n\\n";
    $texto_certificado .= "Codigo de verificacion: " . $codigo_verificacion;
    
    $contenido .= "4 0 obj\n";
    $contenido .= "<<\n";
    $contenido .= "/Length " . (strlen($texto_certificado) + 100) . "\n";
    $contenido .= ">>\n";
    $contenido .= "stream\n";
    $contenido .= "BT\n";
    $contenido .= "/F1 16 Tf\n";
    $contenido .= "50 700 Td\n";
    $contenido .= "($texto_certificado) Tj\n";
    $contenido .= "ET\n";
    $contenido .= "endstream\n";
    $contenido .= "endobj\n\n";
    
    $contenido .= "5 0 obj\n";
    $contenido .= "<<\n";
    $contenido .= "/Type /Font\n";
    $contenido .= "/Subtype /Type1\n";
    $contenido .= "/BaseFont /Helvetica\n";
    $contenido .= ">>\n";
    $contenido .= "endobj\n\n";
    
    $contenido .= "xref\n";
    $contenido .= "0 6\n";
    $contenido .= "0000000000 65535 f \n";
    $contenido .= "0000000010 65535 n \n";
    $contenido .= "0000000053 65535 n \n";
    $contenido .= "0000000125 65535 n \n";
    $contenido .= "0000000348 65535 n \n";
    $contenido .= "0000000500 65535 n \n";
    $contenido .= "trailer\n";
    $contenido .= "<<\n";
    $contenido .= "/Size 6\n";
    $contenido .= "/Root 1 0 R\n";
    $contenido .= ">>\n";
    $contenido .= "startxref\n";
    $contenido .= "625\n";
    $contenido .= "%%EOF\n";
    
    return $contenido;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados - Sistema de Certificados</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
        
        .nav {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
        }
        
        .nav a {
            display: block;
            padding: 1rem 0;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
            border-bottom: 3px solid transparent;
        }
        
        .nav a:hover, .nav a.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title h2 {
            color: #333;
            font-size: 2rem;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn-back:hover {
            background: #5a6268;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        .required {
            color: #dc3545;
        }
        
        select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s;
            margin-right: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff8f00 100%);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s;
            margin-bottom: 0.5rem;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .info-box {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-box h3 {
            color: #1976d2;
            margin-bottom: 1rem;
        }
        
        .info-box ul {
            margin-left: 1.5rem;
            color: #424242;
        }
        
        .info-box li {
            margin-bottom: 0.5rem;
        }
        
        .participant-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .participant-info h4 {
            color: #333;
            margin-bottom: 1rem;
        }
        
        .participant-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
        
        .generation-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .option-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
        }
        
        .option-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
        }
        
        .option-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .option-title {
            color: #333;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .option-description {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 2rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 1rem;
        }
        
        .loading.show {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav ul {
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .generation-options {
                grid-template-columns: 1fr;
            }
            
            .participant-details {
                grid-template-columns: 1fr;
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
    
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="../index.php">Dashboard</a></li>
                <li><a href="../eventos/listar.php">Eventos</a></li>
                <li><a href="../participantes/listar.php">Participantes</a></li>
                <li><a href="generar.php" class="active">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <h2>🏆 Generar Certificados</h2>
            </div>
            <a href="../index.php" class="btn-back">← Volver al Dashboard</a>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>📋 Información sobre la generación de certificados</h3>
            <ul>
                <li><strong>Generación individual:</strong> Crea un certificado para un participante específico</li>
                <li><strong>Generación masiva:</strong> Crea certificados para todos los participantes de un evento que aún no los tengan</li>
                <li><strong>Códigos únicos:</strong> Cada certificado recibe un código de verificación único</li>
                <li><strong>Formato PDF:</strong> Los certificados se generan en formato PDF de alta calidad</li>
                <li><strong>Validación:</strong> Incluye hash de validación para verificar autenticidad</li>
            </ul>
        </div>
        
        <?php if ($participante_individual): ?>
            <div class="participant-info">
                <h4>👤 Participante Seleccionado</h4>
                <div class="participant-details">
                    <div class="detail-item">
                        <div class="detail-label">Nombre Completo</div>
                        <div class="detail-value"><?php echo htmlspecialchars($participante_individual['nombres'] . ' ' . $participante_individual['apellidos']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Identificación</div>
                        <div class="detail-value"><?php echo htmlspecialchars($participante_individual['numero_identificacion']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Evento</div>
                        <div class="detail-value"><?php echo htmlspecialchars($participante_individual['evento_nombre']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Rol</div>
                        <div class="detail-value"><?php echo htmlspecialchars($participante_individual['rol']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><?php echo htmlspecialchars($participante_individual['correo_electronico']); ?></div>
                    </div>
                </div>
                
                <form method="POST" style="margin-top: 1.5rem;">
                    <input type="hidden" name="accion" value="generar_individual">
                    <input type="hidden" name="participante_id" value="<?php echo $participante_individual['id']; ?>">
                    <input type="hidden" name="evento_id" value="<?php echo $participante_individual['evento_id']; ?>">
                    <button type="submit" class="btn-primary" onclick="return confirm('¿Generar certificado para este participante?')">
                        🏆 Generar Certificado Individual
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="generation-options">
                <div class="option-card">
                    <div class="option-icon">👤</div>
                    <div class="option-title">Generación Individual</div>
                    <div class="option-description">
                        Genere un certificado para un participante específico. Ideal para certificados urgentes o casos especiales.
                    </div>
                    <button onclick="mostrarFormularioIndividual()" class="btn-primary">
                        Generar Individual
                    </button>
                </div>
                
                <div class="option-card">
                    <div class="option-icon">👥</div>
                    <div class="option-title">Generación Masiva</div>
                    <div class="option-description">
                        Genere certificados para todos los participantes de un evento que aún no tengan certificado.
                    </div>
                    <button onclick="mostrarFormularioMasivo()" class="btn-warning">
                        Generar Masivo
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Formulario Individual -->
        <div id="formularioIndividual" class="card" style="display: none;">
            <h3 style="margin-bottom: 1.5rem; color: #333;">👤 Generación Individual</h3>
            <form method="POST">
                <input type="hidden" name="accion" value="buscar_participante">
                
                <div class="form-group">
                    <label for="evento_individual">Evento <span class="required">*</span></label>
                    <select id="evento_individual" name="evento_id" required onchange="cargarParticipantes(this.value)">
                        <option value="">Seleccione un evento</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>">
                                <?php echo htmlspecialchars($evento['nombre']); ?> 
                                (<?php echo formatearFecha($evento['fecha_inicio']); ?> - <?php echo formatearFecha($evento['fecha_fin']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="participante_individual">Participante <span class="required">*</span></label>
                    <select id="participante_individual" name="participante_id" required disabled>
                        <option value="">Primero seleccione un evento</option>
                    </select>
                </div>
                
                <div style="text-align: center;">
                    <button type="submit" class="btn-primary" onclick="return confirmarGeneracion('individual')">
                        🏆 Generar Certificado
                    </button>
                    <button type="button" onclick="ocultarFormularios()" class="btn-back">
                        Cancelar
                    </button>
                </div>
                
                <input type="hidden" name="accion" value="generar_individual">
            </form>
        </div>
        
        <!-- Formulario Masivo -->
        <div id="formularioMasivo" class="card" style="display: none;">
            <h3 style="margin-bottom: 1.5rem; color: #333;">👥 Generación Masiva</h3>
            
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
                <strong>⚠️ Advertencia:</strong> Esta acción generará certificados para TODOS los participantes del evento seleccionado que aún no tengan certificado. Esta operación puede tomar varios minutos dependiendo del número de participantes.
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="evento_masivo">Evento <span class="required">*</span></label>
                    <select id="evento_masivo" name="evento_id" required onchange="mostrarEstadisticasEvento(this.value)">
                        <option value="">Seleccione un evento</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>">
                                <?php echo htmlspecialchars($evento['nombre']); ?> 
                                (<?php echo formatearFecha($evento['fecha_inicio']); ?> - <?php echo formatearFecha($evento['fecha_fin']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="estadisticasEvento" style="display: none; background: #f8f9fa; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <h4 style="margin-bottom: 0.5rem;">📊 Estadísticas del Evento</h4>
                    <div id="contenidoEstadisticas"></div>
                </div>
                
                <div style="text-align: center;">
                    <button type="submit" class="btn-warning" onclick="return confirmarGeneracion('masivo')">
                        👥 Generar Certificados Masivo
                    </button>
                    <button type="button" onclick="ocultarFormularios()" class="btn-back">
                        Cancelar
                    </button>
                </div>
                
                <input type="hidden" name="accion" value="generar_masivo">
            </form>
        </div>
        
        <!-- Loading -->
        <div id="loading" class="loading">
            <div class="spinner"></div>
            <p><strong>Generando certificados...</strong></p>
            <p>Por favor, no cierre esta ventana. Esta operación puede tomar varios minutos.</p>
        </div>
    </div>
    
    <script>
        function mostrarFormularioIndividual() {
            document.getElementById('formularioIndividual').style.display = 'block';
            document.getElementById('formularioMasivo').style.display = 'none';
            document.getElementById('formularioIndividual').scrollIntoView({ behavior: 'smooth' });
        }
        
        function mostrarFormularioMasivo() {
            document.getElementById('formularioMasivo').style.display = 'block';
            document.getElementById('formularioIndividual').style.display = 'none';
            document.getElementById('formularioMasivo').scrollIntoView({ behavior: 'smooth' });
        }
        
        function ocultarFormularios() {
            document.getElementById('formularioIndividual').style.display = 'none';
            document.getElementById('formularioMasivo').style.display = 'none';
        }
        
        function confirmarGeneracion(tipo) {
            const mensaje = tipo === 'individual' 
                ? '¿Está seguro de generar el certificado para este participante?'
                : '¿Está seguro de generar certificados masivos para este evento? Esta operación puede tomar varios minutos.';
            
            if (confirm(mensaje)) {
                document.getElementById('loading').classList.add('show');
                return true;
            }
            return false;
        }
        
        async function cargarParticipantes(eventoId) {
            const select = document.getElementById('participante_individual');
            
            if (!eventoId) {
                select.innerHTML = '<option value="">Primero seleccione un evento</option>';
                select.disabled = true;
                return;
            }
            
            select.innerHTML = '<option value="">Cargando participantes...</option>';
            select.disabled = true;
            
            try {
                // Aquí harías una petición AJAX real a un endpoint PHP
                // Por ahora, simulamos la carga
                setTimeout(() => {
                    select.innerHTML = '<option value="">Seleccione un participante</option>';
                    // Aquí cargarías los participantes reales del evento
                    select.disabled = false;
                }, 500);
                
            } catch (error) {
                select.innerHTML = '<option value="">Error al cargar participantes</option>';
                console.error('Error:', error);
            }
        }
        
        async function mostrarEstadisticasEvento(eventoId) {
            const div = document.getElementById('estadisticasEvento');
            const contenido = document.getElementById('contenidoEstadisticas');
            
            if (!eventoId) {
                div.style.display = 'none';
                return;
            }
            
            contenido.innerHTML = 'Cargando estadísticas...';
            div.style.display = 'block';
            
            try {
                // Aquí harías una petición AJAX real
                setTimeout(() => {
                    contenido.innerHTML = `
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                            <div><strong>Total Participantes:</strong> <span id="totalParticipantes">-</span></div>
                            <div><strong>Con Certificado:</strong> <span id="conCertificado">-</span></div>
                            <div><strong>Sin Certificado:</strong> <span id="sinCertificado">-</span></div>
                            <div><strong>Por Generar:</strong> <span id="porGenerar">-</span></div>
                        </div>
                    `;
                    // Aquí cargarías las estadísticas reales
                }, 500);
                
            } catch (error) {
                contenido.innerHTML = 'Error al cargar estadísticas';
                console.error('Error:', error);
            }
        }
        
        // Ocultar loading si la página se recarga
        window.addEventListener('load', function() {
            document.getElementById('loading').classList.remove('show');
        });
    </script>
</body>
</html>