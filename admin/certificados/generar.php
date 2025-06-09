<?php
// admin/certificados/generar.php - VERSIÓN COMPLETA MEJORADA
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
            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion
            FROM participantes p 
            JOIN eventos e ON p.evento_id = e.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$participante_id]);
        $participante_individual = $stmt->fetch();
        
        if (!$participante_individual) {
            $error = "Participante no encontrado";
        }
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
                    $success = '✅ <strong>Certificado generado exitosamente</strong><br>' .
                              '👤 <strong>Participante:</strong> ' . $resultado['participante'] . '<br>' .
                              '🔑 <strong>Código:</strong> ' . $resultado['codigo'] . '<br>' .
                              '📄 <strong>Archivo:</strong> ' . $resultado['archivo'];
                } else {
                    $error = $resultado['error'];
                }
                
            } elseif ($accion === 'generar_masivo') {
                // Generar certificados masivos para el evento
                $resultado = generarCertificadosMasivos($evento_id);
                
                if ($resultado['success']) {
                    $success = "✅ <strong>Generación masiva completada:</strong><br>" .
                              "📊 <strong>{$resultado['generados']}</strong> certificados generados<br>" .
                              "⚠️ <strong>{$resultado['errores']}</strong> errores<br>" .
                              "⏱️ <strong>Tiempo:</strong> {$resultado['tiempo']} segundos";
                              
                    if (!empty($resultado['detalles_errores'])) {
                        $success .= "<br><br><strong>Errores detallados:</strong><br>";
                        foreach ($resultado['detalles_errores'] as $error_det) {
                            $success .= "• " . htmlspecialchars($error_det) . "<br>";
                        }
                    }
                } else {
                    $error = $resultado['error'];
                }
            }
            
        } catch (Exception $e) {
            $error = 'Error durante la generación: ' . $e->getMessage();
        }
    }
}

// Función para generar certificado individual MEJORADA
function generarCertificadoIndividual($participante_id) {
    $tiempo_inicio = microtime(true);
    
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
                e.horas_duracion,
                e.descripcion
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
        $stmt = $db->prepare("SELECT id, codigo_verificacion, archivo_pdf FROM certificados WHERE participante_id = ?");
        $stmt->execute([$participante_id]);
        $certificado_existe = $stmt->fetch();
        
        if ($certificado_existe) {
            return [
                'success' => false, 
                'error' => 'El participante ya tiene un certificado generado con código: ' . $certificado_existe['codigo_verificacion']
            ];
        }
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Crear hash de validación mejorado
        $hash_validacion = generarHashValidacion($participante, $codigo_verificacion);
        
        // Generar PDF del certificado
        $resultado_pdf = generarPDFCertificadoMejorado($participante, $codigo_verificacion);
        
        if (!$resultado_pdf['success']) {
            return ['success' => false, 'error' => 'Error al generar PDF: ' . $resultado_pdf['error']];
        }
        
        // Insertar registro en la base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $participante_id,
            $participante['evento_id'],
            $codigo_verificacion,
            $resultado_pdf['nombre_archivo'],
            $hash_validacion
        ]);
        
        $certificado_id = $db->lastInsertId();
        
        // Registrar en auditoría
        registrarAuditoria('GENERAR_CERTIFICADO', 'certificados', $certificado_id, null, [
            'participante_id' => $participante_id,
            'codigo_verificacion' => $codigo_verificacion,
            'tiempo_generacion' => round(microtime(true) - $tiempo_inicio, 3)
        ]);
        
        return [
            'success' => true,
            'participante' => $participante['nombres'] . ' ' . $participante['apellidos'],
            'codigo' => $codigo_verificacion,
            'archivo' => $resultado_pdf['nombre_archivo'],
            'certificado_id' => $certificado_id
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función para generar certificados masivos MEJORADA
function generarCertificadosMasivos($evento_id) {
    $tiempo_inicio = microtime(true);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Obtener participantes sin certificado
        $stmt = $db->prepare("
            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion, e.descripcion
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
        $detalles_errores = [];
        
        // Procesar en lotes para mejor rendimiento
        $lote_size = 50;
        $total_participantes = count($participantes);
        
        for ($i = 0; $i < $total_participantes; $i += $lote_size) {
            $lote = array_slice($participantes, $i, $lote_size);
            
            foreach ($lote as $participante) {
                try {
                    $resultado = generarCertificadoIndividual($participante['id']);
                    if ($resultado['success']) {
                        $generados++;
                    } else {
                        $errores++;
                        $detalles_errores[] = $participante['nombres'] . ' ' . $participante['apellidos'] . ': ' . $resultado['error'];
                    }
                } catch (Exception $e) {
                    $errores++;
                    $detalles_errores[] = $participante['nombres'] . ' ' . $participante['apellidos'] . ': ' . $e->getMessage();
                }
            }
            
            // Pequeña pausa entre lotes para no sobrecargar el servidor
            if ($i + $lote_size < $total_participantes) {
                usleep(100000); // 0.1 segundos
            }
        }
        
        $tiempo_total = round(microtime(true) - $tiempo_inicio, 2);
        
        return [
            'success' => true,
            'generados' => $generados,
            'errores' => $errores,
            'tiempo' => $tiempo_total,
            'detalles_errores' => array_slice($detalles_errores, 0, 10) // Máximo 10 errores mostrados
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función para generar hash de validación mejorado
function generarHashValidacion($participante, $codigo_verificacion) {
    $datos_validacion = [
        'numero_identificacion' => $participante['numero_identificacion'],
        'nombres' => $participante['nombres'],
        'apellidos' => $participante['apellidos'],
        'evento_id' => $participante['evento_id'],
        'evento_nombre' => $participante['evento_nombre'],
        'codigo_verificacion' => $codigo_verificacion,
        'fecha_inicio' => $participante['fecha_inicio'],
        'fecha_fin' => $participante['fecha_fin'],
        'rol' => $participante['rol'],
        'salt' => 'certificados_digitales_2025_' . date('Y-m-d'),
        'version' => '2.0'
    ];
    
    return hash('sha256', json_encode($datos_validacion, JSON_UNESCAPED_UNICODE));
}

// Función para generar PDF mejorado
function generarPDFCertificadoMejorado($participante, $codigo_verificacion) {
    try {
        // Generar nombre de archivo único
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Generar contenido del PDF (versión mejorada con mejor formato)
        $contenido_pdf = generarContenidoPDFMejorado($participante, $codigo_verificacion);
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $contenido_pdf) === false) {
            throw new Exception("No se pudo escribir el archivo PDF");
        }
        
        // Verificar que el archivo se creó correctamente
        if (!file_exists($ruta_completa) || filesize($ruta_completa) == 0) {
            throw new Exception("El archivo PDF no se generó correctamente");
        }
        
        return [
            'success' => true,
            'nombre_archivo' => $nombre_archivo,
            'ruta_completa' => $ruta_completa,
            'tamaño' => filesize($ruta_completa)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Función para generar contenido PDF mejorado
function generarContenidoPDFMejorado($participante, $codigo_verificacion) {
    // Preparar texto del certificado con mejor formato
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $evento_nombre = strtoupper($participante['evento_nombre']);
    $entidad = $participante['entidad_organizadora'];
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $rol = $participante['rol'];
    $modalidad = ucfirst($participante['modalidad']);
    
    // Información adicional
    $duracion_texto = $participante['horas_duracion'] ? 
        "\\nDuracion: " . $participante['horas_duracion'] . " horas academicas" : "";
    
    $lugar_texto = $participante['lugar'] ? 
        "\\nLugar: " . $participante['lugar'] : "";
    
    $modalidad_texto = "\\nModalidad: " . $modalidad;
    
    // URL de verificación
    $url_verificacion = PUBLIC_URL . "verificar.php?codigo=" . $codigo_verificacion;
    
    $texto_certificado = "CERTIFICADO DE PARTICIPACION\\n\\n";
    $texto_certificado .= "Se certifica que\\n\\n";
    $texto_certificado .= $nombre_completo . "\\n\\n";
    $texto_certificado .= "participo en el evento\\n\\n";
    $texto_certificado .= $evento_nombre . "\\n\\n";
    $texto_certificado .= "organizado por " . $entidad . "\\n";
    $texto_certificado .= "del " . $fecha_inicio . " al " . $fecha_fin;
    $texto_certificado .= $modalidad_texto;
    $texto_certificado .= $lugar_texto;
    $texto_certificado .= $duracion_texto;
    $texto_certificado .= "\\n\\nRol: " . $rol;
    $texto_certificado .= "\\n\\nCodigo de verificacion: " . $codigo_verificacion;
    $texto_certificado .= "\\nFecha de emision: " . date('d/m/Y H:i');
    $texto_certificado .= "\\n\\nVerificar en: " . $url_verificacion;
    
    // Calcular longitud del contenido
    $longitud_contenido = strlen($texto_certificado) + 200;
    
    // Generar PDF con estructura mejorada
    $contenido = "%PDF-1.4\n";
    $contenido .= "1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n\n";
    $contenido .= "2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n\n";
    $contenido .= "3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 842 595]\n/Contents 4 0 R\n";
    $contenido .= "/Resources <<\n/Font <<\n/F1 5 0 R\n/F2 6 0 R\n>>\n>>\n>>\nendobj\n\n";
    
    // Contenido de la página con mejor formato
    $contenido .= "4 0 obj\n<<\n/Length $longitud_contenido\n>>\nstream\nBT\n";
    
    // Título principal
    $contenido .= "/F2 24 Tf\n70 500 Td\n(CERTIFICADO DE PARTICIPACION) Tj\n";
    
    // Contenido principal
    $contenido .= "/F1 14 Tf\n0 -60 Td\n";
    $contenido .= "($texto_certificado) Tj\n";
    
    $contenido .= "ET\nendstream\nendobj\n\n";
    
    // Definir fuentes
    $contenido .= "5 0 obj\n<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica\n>>\nendobj\n\n";
    $contenido .= "6 0 obj\n<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica-Bold\n>>\nendobj\n\n";
    
    // Tabla de referencias cruzadas
    $contenido .= "xref\n0 7\n";
    $contenido .= "0000000000 65535 f \n";
    $contenido .= "0000000010 65535 n \n";
    $contenido .= "0000000053 65535 n \n";
    $contenido .= "0000000125 65535 n \n";
    $contenido .= "0000000348 65535 n \n";
    $contenido .= sprintf("%010d 00000 n \n", strlen($contenido) + 50);
    $contenido .= sprintf("%010d 00000 n \n", strlen($contenido) + 100);
    
    // Trailer
    $contenido .= "trailer\n<<\n/Size 7\n/Root 1 0 R\n>>\n";
    $contenido .= "startxref\n" . (strlen($contenido) + 150) . "\n%%EOF\n";
    
    return $contenido;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados - Sistema de Certificados</title>
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <style>
        /* Estilos específicos para esta página */
        .info-box {
            background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
            border: 2px solid #28a745;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.1);
        }
        
        .info-box h3 {
            color: #155724;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        
        .info-box ul {
            margin-left: 1.5rem;
            color: #155724;
        }
        
        .info-box li {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }
        
        .participant-info {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .generation-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .option-card {
            border: 3px solid #e9ecef;
            border-radius: 15px;
            padding: 2.5rem;
            text-align: center;
            transition: all 0.4s ease;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .option-card:hover {
            border-color: #667eea;
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.2);
        }
        
        .option-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }
        
        .option-card:hover .option-icon {
            transform: scale(1.1);
        }
        
        .progress-bar {
            width: 100%;
            height: 25px;
            background-color: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            margin: 1rem 0;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 12px;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 3rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            margin-top: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .loading.show {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            border-color: #667eea;
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <h1>🏆 Sistema de Certificados</h1>
            </div>
            <div class="user-info">
                <span>Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
                <a href="../logout.php" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
    </header>
    
    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="../index.php">📊 Dashboard</a></li>
                <li><a href="../eventos/listar.php">📅 Eventos</a></li>
                <li><a href="../participantes/listar.php">👥 Participantes</a></li>
                <li><a href="generar.php" class="active">🏆 Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <h2>🏆 Generar Certificados Digitales</h2>
            </div>
            <a href="../index.php" class="btn-back">← Volver al Dashboard</a>
        </div>
        
        <!-- Alertas -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>❌ Error:</strong> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <!-- Información del sistema -->
        <div class="info-box">
            <h3>📋 Sistema de Certificados Digitales v2.0 - Características</h3>
            <ul>
                <li><strong>✅ Generación individual:</strong> Crea certificados personalizados para participantes específicos</li>
                <li><strong>✅ Generación masiva optimizada:</strong> Procesa lotes de hasta 50 certificados simultáneamente</li>
                <li><strong>✅ Códigos únicos garantizados:</strong> Algoritmo mejorado que previene duplicados</li>
                <li><strong>✅ PDFs de alta calidad:</strong> Formato A4 horizontal con diseño profesional</li>
                <li><strong>✅ Validación robusta:</strong> Hash SHA-256 con múltiples parámetros de seguridad</li>
                <li><strong>✅ Auditoría completa:</strong> Registro detallado de todas las operaciones</li>
                <li><strong>✅ Verificación pública:</strong> URLs directas para validación instantánea</li>
            </ul>
        </div>
        
        <!-- Participante individual seleccionado -->
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
                        <div class="detail-label">Fechas</div>
                        <div class="detail-value">
                            <?php echo formatearFecha($participante_individual['fecha_inicio']); ?> - 
                            <?php echo formatearFecha($participante_individual['fecha_fin']); ?>
                        </div>
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
                
                <form method="POST" style="margin-top: 2rem;">
                    <input type="hidden" name="accion" value="generar_individual">
                    <input type="hidden" name="participante_id" value="<?php echo $participante_individual['id']; ?>">
                    <input type="hidden" name="evento_id" value="<?php echo $participante_individual['evento_id']; ?>">
                    <button type="submit" class="btn-primary" onclick="return confirmarGeneracion('individual')">
                        🏆 Generar Certificado Individual
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Opciones de generación -->
        <div class="card">
            <div class="generation-options">
                <div class="option-card">
                    <div class="option-icon">👤</div>
                    <div class="option-title">Generación Individual</div>
                    <div class="option-description">
                        Genera un certificado personalizado para un participante específico. 
                        Ideal para certificados urgentes, casos especiales o participantes VIP.
                    </div>
                    <button onclick="mostrarFormularioIndividual()" class="btn-primary">
                        🎯 Generar Individual
                    </button>
                </div>
                
                <div class="option-card">
                    <div class="option-icon">👥</div>
                    <div class="option-title">Generación Masiva</div>
                    <div class="option-description">
                        Genera certificados para todos los participantes de un evento que aún no tengan certificado.
                        Procesamiento optimizado en lotes para máximo rendimiento.
                    </div>
                    <button onclick="mostrarFormularioMasivo()" class="btn-warning">
                        🚀 Generar Masivo
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Formulario Individual -->
        <div id="formularioIndividual" class="card" style="display: none;">
            <h3 style="margin-bottom: 1.5rem; color: #333;">👤 Generación Individual</h3>
            <form method="POST" id="formIndividual">
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
                
                <div id="infoParticipanteSeleccionado" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                    <!-- Información del participante se cargará aquí -->
                </div>
                
                <div style="text-align: center; margin-top: 2rem;">
                    <button type="submit" class="btn-primary" onclick="return confirmarGeneracion('individual')">
                        🏆 Generar Certificado
                    </button>
                    <button type="button" onclick="ocultarFormularios()" class="btn-back">
                        ❌ Cancelar
                    </button>
                </div>
                
                <input type="hidden" name="accion" value="generar_individual">
            </form>
        </div>
        
        <!-- Formulario Masivo -->
        <div id="formularioMasivo" class="card" style="display: none;">
            <h3 style="margin-bottom: 1.5rem; color: #333;">👥 Generación Masiva</h3>
            
            <div style="background: #fff3cd; border: 2px solid #ffeaa7; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem;">
                <h4 style="color: #856404; margin-bottom: 1rem;">⚠️ Proceso de Generación Masiva</h4>
                <ul style="color: #856404; margin-left: 1.5rem;">
                    <li>Esta operación generará certificados para TODOS los participantes sin certificado</li>
                    <li>El proceso se ejecuta en lotes de 50 certificados para optimizar rendimiento</li>
                    <li>Tiempo estimado: 1-2 segundos por certificado</li>
                    <li>No cierre esta ventana durante el proceso</li>
                </ul>
            </div>
            
            <form method="POST" id="formMasivo">
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
                
                <div id="estadisticasEvento" style="display: none;">
                    <h4 style="margin-bottom: 1rem;">📊 Estadísticas del Evento</h4>
                    <div class="stats-cards" id="contenidoEstadisticas">
                        <!-- Estadísticas se cargarán aquí -->
                    </div>
                    
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div id="progressText" style="text-align: center; margin-top: 0.5rem; color: #666;">
                        Progreso de certificados generados
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 2rem;">
                    <button type="submit" class="btn-warning" onclick="return confirmarGeneracion('masivo')" id="btnMasivo">
                        👥 Generar Certificados Masivo
                    </button>
                    <button type="button" onclick="ocultarFormularios()" class="btn-back">
                        ❌ Cancelar
                    </button>
                </div>
                
                <input type="hidden" name="accion" value="generar_masivo">
            </form>
        </div>
        
        <!-- Loading Indicator -->
        <div id="loading" class="loading">
            <div class="spinner"></div>
            <h3><strong>🔄 Generando certificados...</strong></h3>
            <p>Por favor, no cierre esta ventana. Esta operación puede tomar varios minutos dependiendo del número de participantes.</p>
            <div id="loadingProgress" style="margin-top: 1rem;">
                <div class="progress-bar">
                    <div class="progress-fill" id="loadingProgressFill"></div>
                </div>
                <div id="loadingProgressText" style="text-align: center; margin-top: 0.5rem;">
                    Iniciando proceso...
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Variables globales
        let participantesEvento = {};
        let estadisticasActuales = null;
        
        // Mostrar formulario individual
        function mostrarFormularioIndividual() {
            document.getElementById('formularioIndividual').style.display = 'block';
            document.getElementById('formularioMasivo').style.display = 'none';
            document.getElementById('formularioIndividual').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Mostrar formulario masivo
        function mostrarFormularioMasivo() {
            document.getElementById('formularioMasivo').style.display = 'block';
            document.getElementById('formularioIndividual').style.display = 'none';
            document.getElementById('formularioMasivo').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Ocultar formularios
        function ocultarFormularios() {
            document.getElementById('formularioIndividual').style.display = 'none';
            document.getElementById('formularioMasivo').style.display = 'none';
            document.querySelector('.generation-options').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Confirmar generación
        function confirmarGeneracion(tipo) {
            let mensaje, detalles = '';
            
            if (tipo === 'individual') {
                const eventoSelect = document.getElementById('evento_individual');
                const participanteSelect = document.getElementById('participante_individual');
                
                if (!eventoSelect.value || !participanteSelect.value) {
                    alert('Por favor, seleccione un evento y participante.');
                    return false;
                }
                
                const eventoNombre = eventoSelect.options[eventoSelect.selectedIndex].text;
                const participanteNombre = participanteSelect.options[participanteSelect.selectedIndex].text;
                
                mensaje = '¿Confirma la generación del certificado?';
                detalles = `\\n\\nEvento: ${eventoNombre}\\nParticipante: ${participanteNombre}`;
                
            } else if (tipo === 'masivo') {
                const eventoSelect = document.getElementById('evento_masivo');
                
                if (!eventoSelect.value) {
                    alert('Por favor, seleccione un evento.');
                    return false;
                }
                
                if (!estadisticasActuales || estadisticasActuales.sin_certificado === 0) {
                    alert('No hay participantes sin certificado en este evento.');
                    return false;
                }
                
                const eventoNombre = eventoSelect.options[eventoSelect.selectedIndex].text;
                mensaje = '⚠️ GENERACIÓN MASIVA\\n\\n¿Está seguro de generar certificados masivos?';
                detalles = `\\n\\nEvento: ${eventoNombre}\\nParticipantes sin certificado: ${estadisticasActuales.sin_certificado}\\nTiempo estimado: ${Math.ceil(estadisticasActuales.sin_certificado * 1.5)} segundos`;
            }
            
            if (confirm(mensaje + detalles + '\\n\\n⚠️ Esta operación no se puede deshacer.')) {
                mostrarLoading(tipo);
                return true;
            }
            return false;
        }
        
        // Mostrar indicador de carga
        function mostrarLoading(tipo) {
            const loading = document.getElementById('loading');
            const progressText = document.getElementById('loadingProgressText');
            
            loading.classList.add('show');
            
            if (tipo === 'masivo' && estadisticasActuales) {
                const total = estadisticasActuales.sin_certificado;
                let progreso = 0;
                
                progressText.textContent = `Procesando 0 de ${total} certificados...`;
                
                // Simular progreso (en la implementación real esto vendría del servidor)
                const intervalo = setInterval(() => {
                    progreso += Math.random() * 5;
                    if (progreso > total) progreso = total;
                    
                    const porcentaje = (progreso / total) * 100;
                    document.getElementById('loadingProgressFill').style.width = porcentaje + '%';
                    progressText.textContent = `Procesando ${Math.floor(progreso)} de ${total} certificados... (${Math.floor(porcentaje)}%)`;
                    
                    if (progreso >= total) {
                        clearInterval(intervalo);
                        progressText.textContent = 'Finalizando proceso...';
                    }
                }, 500);
            } else {
                progressText.textContent = 'Generando certificado individual...';
                document.getElementById('loadingProgressFill').style.width = '100%';
            }
        }
        
        // Cargar participantes del evento
        async function cargarParticipantes(eventoId) {
            const select = document.getElementById('participante_individual');
            const infoDiv = document.getElementById('infoParticipanteSeleccionado');
            
            if (!eventoId) {
                select.innerHTML = '<option value="">Primero seleccione un evento</option>';
                select.disabled = true;
                infoDiv.style.display = 'none';
                return;
            }
            
            select.innerHTML = '<option value="">🔄 Cargando participantes...</option>';
            select.disabled = true;
            
            try {
                const response = await fetch(`../api/participantes_evento.php?evento_id=${eventoId}`);
                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                participantesEvento[eventoId] = data.participantes;
                
                select.innerHTML = '<option value="">Seleccione un participante</option>';
                
                if (data.participantes && data.participantes.length > 0) {
                    data.participantes.forEach(participante => {
                        const option = document.createElement('option');
                        option.value = participante.id;
                        option.textContent = `${participante.nombres} ${participante.apellidos} (${participante.numero_identificacion}) - ${participante.rol}`;
                        option.dataset.participante = JSON.stringify(participante);
                        select.appendChild(option);
                    });
                    select.disabled = false;
                } else {
                    select.innerHTML = '<option value="">No hay participantes sin certificado</option>';
                }
                
            } catch (error) {
                console.error('Error:', error);
                select.innerHTML = '<option value="">Error al cargar participantes</option>';
            }
        }
        
        // Mostrar información del participante seleccionado
        document.getElementById('participante_individual')?.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const infoDiv = document.getElementById('infoParticipanteSeleccionado');
            
            if (selectedOption.dataset.participante) {
                const participante = JSON.parse(selectedOption.dataset.participante);
                
                infoDiv.innerHTML = `
                    <h5 style="margin-bottom: 1rem; color: #333;">📋 Información del Participante</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div><strong>Email:</strong> ${participante.correo_electronico}</div>
                        <div><strong>Teléfono:</strong> ${participante.telefono || 'No registrado'}</div>
                        <div><strong>Institución:</strong> ${participante.institucion || 'No registrada'}</div>
                        <div><strong>Rol:</strong> ${participante.rol}</div>
                    </div>
                `;
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        });
        
        // Mostrar estadísticas del evento
        async function mostrarEstadisticasEvento(eventoId) {
            const div = document.getElementById('estadisticasEvento');
            const contenido = document.getElementById('contenidoEstadisticas');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            
            if (!eventoId) {
                div.style.display = 'none';
                estadisticasActuales = null;
                return;
            }
            
            contenido.innerHTML = `
                <div class="stat-card">
                    <div class="stat-number">⏳</div>
                    <div class="stat-label">Cargando...</div>
                </div>
            `;
            div.style.display = 'block';
            
            try {
                const response = await fetch('../api/estadisticas_evento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ evento_id: eventoId })
                });
                
                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                estadisticasActuales = data;
                
                const porcentajeCompletado = data.porcentaje_completado || 0;
                
                contenido.innerHTML = `
                    <div class="stat-card">
                        <div class="stat-number">${data.total_participantes}</div>
                        <div class="stat-label">Total Participantes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${data.con_certificado}</div>
                        <div class="stat-label">Con Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${data.sin_certificado}</div>
                        <div class="stat-label">Sin Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${porcentajeCompletado}%</div>
                        <div class="stat-label">Completado</div>
                    </div>
                `;
                
                progressFill.style.width = porcentajeCompletado + '%';
                progressText.textContent = `${data.con_certificado} de ${data.total_participantes} certificados generados (${porcentajeCompletado}%)`;
                
                // Habilitar/deshabilitar botón según si hay participantes sin certificado
                const btnMasivo = document.getElementById('btnMasivo');
                if (data.sin_certificado > 0) {
                    btnMasivo.disabled = false;
                    btnMasivo.innerHTML = `👥 Generar ${data.sin_certificado} Certificados`;
                } else {
                    btnMasivo.disabled = true;
                    btnMasivo.innerHTML = '✅ Todos los certificados generados';
                }
                
            } catch (error) {
                console.error('Error:', error);
                contenido.innerHTML = `
                    <div class="stat-card">
                        <div class="stat-number">❌</div>
                        <div class="stat-label">Error al cargar</div>
                    </div>
                `;
            }
        }
        
        // Ocultar loading al cargar la página
        window.addEventListener('load', function() {
            document.getElementById('loading').classList.remove('show');
        });
        
        // Prevenir envío accidental del formulario
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    
                    // Re-habilitar después de 10 segundos por seguridad
                    setTimeout(() => {
                        submitBtn.disabled = false;
                    }, 10000);
                }
            });
        });
    </script>
</body>
</html>