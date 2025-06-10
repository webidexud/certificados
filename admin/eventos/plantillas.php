<?php
// admin/eventos/plantillas.php - VERSIÓN ACTUALIZADA CON NÚMERO DE IDENTIFICACIÓN
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : 0;
$error = '';
$success = '';

if (!$evento_id) {
    header('Location: listar.php');
    exit;
}

// Obtener información del evento
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
    $stmt->execute([$evento_id]);
    $evento = $stmt->fetch();
    
    if (!$evento) {
        mostrarMensaje('error', 'Evento no encontrado');
        header('Location: listar.php');
        exit;
    }
} catch (Exception $e) {
    $error = "Error al cargar el evento: " . $e->getMessage();
}

// Obtener plantillas existentes
try {
    $stmt = $db->prepare("SELECT * FROM plantillas_certificados WHERE evento_id = ? ORDER BY rol, created_at DESC");
    $stmt->execute([$evento_id]);
    $plantillas = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar plantillas: " . $e->getMessage();
}

// Procesar formulario de subida
if ($_POST && isset($_FILES['archivo_plantilla'])) {
    $rol = limpiarDatos($_POST['rol']);
    $nombre_plantilla = limpiarDatos($_POST['nombre_plantilla']);
    $archivo = $_FILES['archivo_plantilla'];
    
    if (empty($rol) || empty($nombre_plantilla)) {
        $error = 'Por favor, complete todos los campos obligatorios';
    } elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo: ' . $archivo['error'];
    } else {
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        
        if ($extension !== 'svg') {
            $error = 'Solo se permiten archivos SVG (.svg)';
        } else {
            try {
                // Leer y validar contenido SVG
                $contenido_svg = file_get_contents($archivo['tmp_name']);
                
                if (empty($contenido_svg)) {
                    throw new Exception("El archivo está vacío");
                }
                
                // Validar que es un SVG válido
                if (strpos($contenido_svg, '<svg') === false) {
                    throw new Exception("El archivo no parece ser un SVG válido");
                }
                
                // Variables requeridas (incluyendo número de identificación)
                $variables_requeridas = [
                    '{{nombres}}', 
                    '{{apellidos}}', 
                    '{{evento_nombre}}', 
                    '{{codigo_verificacion}}',
                    '{{numero_identificacion}}'  // NUEVA VARIABLE REQUERIDA
                ];
                $variables_faltantes = [];
                
                foreach ($variables_requeridas as $variable) {
                    if (strpos($contenido_svg, $variable) === false) {
                        $variables_faltantes[] = $variable;
                    }
                }
                
                if (!empty($variables_faltantes)) {
                    $error = 'La plantilla SVG debe contener las siguientes variables obligatorias: ' . implode(', ', $variables_faltantes);
                } else {
                    // Generar nombre único para el archivo
                    $nombre_archivo = 'plantilla_' . $evento_id . '_' . $rol . '_' . time() . '.svg';
                    $ruta_destino = TEMPLATE_PATH . $nombre_archivo;
                    
                    // Limpiar y optimizar SVG
                    $svg_limpio = limpiarSVG($contenido_svg);
                    
                    // Guardar archivo
                    if (file_put_contents($ruta_destino, $svg_limpio)) {
                        // Extraer variables disponibles del SVG
                        preg_match_all('/\{\{([^}]+)\}\}/', $svg_limpio, $matches);
                        $variables_disponibles = array_unique($matches[1]);
                        
                        // Extraer dimensiones del SVG
                        $dimensiones = extraerDimensionesSVG($svg_limpio);
                        
                        // Verificar si ya existe una plantilla para este rol
                        $stmt = $db->prepare("SELECT id FROM plantillas_certificados WHERE evento_id = ? AND rol = ?");
                        $stmt->execute([$evento_id, $rol]);
                        $plantilla_existente = $stmt->fetch();
                        
                        if ($plantilla_existente) {
                            // Actualizar plantilla existente
                            $stmt = $db->prepare("
                                UPDATE plantillas_certificados 
                                SET archivo_plantilla = ?, variables_disponibles = ?, nombre_plantilla = ?, 
                                    ancho = ?, alto = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $nombre_archivo,
                                json_encode($variables_disponibles),
                                $nombre_plantilla,
                                $dimensiones['ancho'],
                                $dimensiones['alto'],
                                $plantilla_existente['id']
                            ]);
                            
                            registrarAuditoria('UPDATE', 'plantillas_certificados', $plantilla_existente['id']);
                            $success = "✅ Plantilla SVG actualizada exitosamente para el rol: <strong>$rol</strong><br>📏 Dimensiones: {$dimensiones['ancho']}x{$dimensiones['alto']}px<br>🔧 Variables: " . count($variables_disponibles);
                        } else {
                            // Crear nueva plantilla
                            $stmt = $db->prepare("
                                INSERT INTO plantillas_certificados (evento_id, rol, archivo_plantilla, variables_disponibles, nombre_plantilla, ancho, alto) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $evento_id,
                                $rol,
                                $nombre_archivo,
                                json_encode($variables_disponibles),
                                $nombre_plantilla,
                                $dimensiones['ancho'],
                                $dimensiones['alto']
                            ]);
                            
                            registrarAuditoria('CREATE', 'plantillas_certificados', $db->lastInsertId());
                            $success = "✅ Plantilla SVG creada exitosamente para el rol: <strong>$rol</strong><br>📏 Dimensiones: {$dimensiones['ancho']}x{$dimensiones['alto']}px<br>🔧 Variables encontradas: " . count($variables_disponibles);
                        }
                        
                        // Recargar plantillas
                        $stmt = $db->prepare("SELECT * FROM plantillas_certificados WHERE evento_id = ? ORDER BY rol, created_at DESC");
                        $stmt->execute([$evento_id]);
                        $plantillas = $stmt->fetchAll();
                        
                    } else {
                        throw new Exception("Error al guardar el archivo SVG");
                    }
                }
                
            } catch (Exception $e) {
                $error = 'Error al procesar la plantilla SVG: ' . $e->getMessage();
            }
        }
    }
}

// Eliminar plantilla
if (isset($_GET['eliminar'])) {
    $plantilla_id = intval($_GET['eliminar']);
    
    try {
        // Obtener información de la plantilla antes de eliminar
        $stmt = $db->prepare("SELECT * FROM plantillas_certificados WHERE id = ? AND evento_id = ?");
        $stmt->execute([$plantilla_id, $evento_id]);
        $plantilla = $stmt->fetch();
        
        if ($plantilla) {
            // Eliminar archivo físico
            $ruta_archivo = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
            if (file_exists($ruta_archivo)) {
                unlink($ruta_archivo);
            }
            
            // Eliminar registro de la base de datos
            $stmt = $db->prepare("DELETE FROM plantillas_certificados WHERE id = ?");
            $stmt->execute([$plantilla_id]);
            
            registrarAuditoria('DELETE', 'plantillas_certificados', $plantilla_id, $plantilla);
            mostrarMensaje('success', 'Plantilla SVG eliminada exitosamente');
        }
        
        header("Location: plantillas.php?evento_id=$evento_id");
        exit;
        
    } catch (Exception $e) {
        $error = 'Error al eliminar la plantilla: ' . $e->getMessage();
    }
}

// Funciones auxiliares para SVG
function limpiarSVG($contenido_svg) {
    // Limpiar el SVG de posibles elementos maliciosos
    $svg_limpio = $contenido_svg;
    
    // Remover scripts y elementos peligrosos
    $elementos_peligrosos = ['<script', '<iframe', '<object', '<embed', '<link', 'javascript:', 'data:'];
    foreach ($elementos_peligrosos as $elemento) {
        $svg_limpio = str_ireplace($elemento, '', $svg_limpio);
    }
    
    // Asegurar que tenga la declaración XML
    if (strpos($svg_limpio, '<?xml') === false) {
        $svg_limpio = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg_limpio;
    }
    
    return $svg_limpio;
}

function extraerDimensionesSVG($contenido_svg) {
    $ancho = 1200; // Valor por defecto
    $alto = 850;
    
    // Buscar atributos width y height en el SVG
    if (preg_match('/width=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $ancho = intval($matches[1]);
    }
    
    if (preg_match('/height=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $alto = intval($matches[1]);
    }
    
    // Si no se encuentran, buscar en viewBox
    if (preg_match('/viewBox=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $viewBox = explode(' ', $matches[1]);
        if (count($viewBox) >= 4) {
            $ancho = intval($viewBox[2]);
            $alto = intval($viewBox[3]);
        }
    }
    
    return ['ancho' => $ancho, 'alto' => $alto];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plantillas SVG de Certificados</title>
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
        
        .logo h1 { font-size: 1.5rem; }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .btn-logout:hover { background: rgba(255,255,255,0.3); }
        
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 20px;
        }
        
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .breadcrumb {
            color: #666;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .page-title h2 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .event-info {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #2196f3;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
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
        
        .required { color: #dc3545; }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-input-display {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 3px dashed #667eea;
            border-radius: 10px;
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f4ff 100%);
            cursor: pointer;
            transition: all 0.3s;
            min-height: 80px;
        }
        
        .file-input-display:hover {
            border-color: #5a67d8;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8efff 100%);
            transform: translateY(-2px);
        }
        
        .file-icon {
            font-size: 3rem;
            margin-right: 1rem;
            color: #667eea;
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
        }
        
        .btn-primary:hover { transform: translateY(-2px); }
        
        .btn-back {
            background: #6c757d;
            color: white;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn-back:hover { background: #5a6268; }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
            text-align: center;
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
        
        .plantillas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .plantilla-card {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1.5rem;
            transition: all 0.3s;
            background: white;
            position: relative;
            overflow: hidden;
        }
        
        .plantilla-card::before {
            content: '🎨';
            position: absolute;
            top: -10px;
            right: -10px;
            font-size: 6rem;
            opacity: 0.1;
            z-index: 1;
        }
        
        .plantilla-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.2);
        }
        
        .plantilla-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
        }
        
        .rol-badge {
            padding: 0.4rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .rol-participante { background: #d4edda; color: #155724; }
        .rol-ponente { background: #fff3cd; color: #856404; }
        .rol-organizador { background: #d1ecf1; color: #0c5460; }
        .rol-moderador { background: #e2e3e5; color: #383d41; }
        .rol-asistente { background: #f8d7da; color: #721c24; }
        
        .plantilla-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            max-height: 200px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #dee2e6;
        }
        
        .svg-miniature {
            max-width: 100%;
            max-height: 150px;
            opacity: 0.8;
            transition: opacity 0.3s;
        }
        
        .plantilla-card:hover .svg-miniature {
            opacity: 1;
        }
        
        .plantilla-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            position: relative;
            z-index: 2;
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .btn-edit { background: #28a745; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-preview { background: #17a2b8; color: white; }
        .btn-download { background: #6f42c1; color: white; }
        
        .btn-sm:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .variables-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid #667eea;
        }
        
        .variables-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .variable-tag {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.25rem 0.6rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        
        .help-box {
            background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
            border: 2px solid #28a745;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .help-box::before {
            content: '📋';
            position: absolute;
            top: -20px;
            right: -20px;
            font-size: 8rem;
            opacity: 0.1;
        }
        
        .help-box h3 {
            color: #155724;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        
        .help-box ul {
            margin-left: 1.5rem;
            color: #155724;
        }
        
        .help-box li {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem;
            color: #666;
        }
        
        .empty-state .icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        
        .svg-dimensions {
            background: #e3f2fd;
            padding: 0.5rem;
            border-radius: 5px;
            font-size: 0.85rem;
            color: #1976d2;
            margin-top: 0.5rem;
        }
        
        .download-template {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .download-template h4 {
            color: #856404;
            margin-bottom: 1rem;
        }
        
        .btn-download-template {
            background: #ffc107;
            color: #212529;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-download-template:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <h1>🎨 Sistema de Certificados SVG</h1>
            </div>
            <div class="user-info">
                <span>Bienvenido, Usuario Demo</span>
                <a href="#" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="#">Dashboard</a></li>
                <li><a href="#" class="active">Eventos</a></li>
                <li><a href="#">Participantes</a></li>
                <li><a href="#">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="breadcrumb">
                <a href="#">Eventos</a> > <a href="#">Evento Demo</a> > Plantillas SVG
            </div>
            <div class="page-title">
                <h2>🎨 Plantillas SVG de Certificados</h2>
            </div>
            <div class="event-info">
                <strong>📅 Evento:</strong> Evento Demostración<br>
                <strong>📍 Fechas:</strong> 01/06/2025 - 30/06/2025
            </div>
        </div>
        
        
        
        <div class="help-box">
            <h3>🎯 Cómo crear plantillas SVG con todas las variables</h3>
            <ul>
                <li><strong>🎨 Formato SVG:</strong> Cree un archivo SVG vectorial para máxima calidad y escalabilidad</li>
                <li><strong>📏 Dimensiones recomendadas:</strong> 1200x850px para formato horizontal profesional</li>
                <li><strong>🔧 Variables obligatorias:</strong> 
                    <code>{{nombres}}</code>, <code>{{apellidos}}</code>, <code>{{evento_nombre}}</code>, 
                    <code>{{codigo_verificacion}}</code>, <strong><code>{{numero_identificacion}}</code></strong>
                </li>
                <li><strong>🎛️ Variables opcionales:</strong> 
                    <code>{{fecha_inicio}}</code>, <code>{{fecha_fin}}</code>, <code>{{rol}}</code>, 
                    <code>{{entidad_organizadora}}</code>, <code>{{modalidad}}</code>, <code>{{lugar}}</code>, 
                    <code>{{horas_duracion}}</code>, <code>{{url_verificacion}}</code>
                </li>
                <li><strong>✨ Extras disponibles:</strong> 
                    <code>{{numero_certificado}}</code>, <code>{{fecha_generacion}}</code>, 
                    <code>{{año}}</code>, <code>{{mes}}</code>, <code>{{dia}}</code>
                </li>
                <li><strong>📝 Uso:</strong> Coloque las variables dentro de elementos <code>&lt;text&gt;</code> del SVG</li>
                <li><strong>🔗 Código QR:</strong> Incluya un área para código QR que se generará automáticamente</li>
                <li><strong>🛡️ Seguridad:</strong> No incluya scripts ni elementos externos por seguridad</li>
            </ul>
        </div>
        
        <div class="card">
            <h3 style="margin-bottom: 1.5rem;">➕ Subir Nueva Plantilla SVG</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nombre_plantilla">Nombre de la Plantilla <span class="required">*</span></label>
                        <input type="text" id="nombre_plantilla" name="nombre_plantilla" required placeholder="Ej: Certificado Moderno con QR">
                    </div>
                    
                    <div class="form-group">
                        <label for="rol">Rol del Participante <span class="required">*</span></label>
                        <select id="rol" name="rol" required>
                            <option value="">Seleccione un rol</option>
                            <option value="Participante">Participante</option>
                            <option value="Ponente">Ponente</option>
                            <option value="Organizador">Organizador</option>
                            <option value="Moderador">Moderador</option>
                            <option value="Asistente">Asistente</option>
                            <option value="General">General (para todos los roles)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="archivo_plantilla">Archivo SVG <span class="required">*</span></label>
                    <div class="file-input-wrapper">
                        <input type="file" id="archivo_plantilla" name="archivo_plantilla" class="file-input" accept=".svg" required>
                        <div class="file-input-display">
                            <div class="file-icon">🎨</div>
                            <div>
                                <div style="font-weight: 600; font-size: 1.1rem; color: #333;">Seleccionar archivo SVG</div>
                                <div style="font-size: 0.9rem; color: #666; margin-top: 0.25rem;">
                                    Debe incluir: {{nombres}}, {{apellidos}}, {{evento_nombre}}, {{codigo_verificacion}}, {{numero_identificacion}}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="text-align: center;">
                    <button type="submit" class="btn-primary">🚀 Subir Plantilla SVG</button>
                    <a href="#" class="btn-back">← Volver a Eventos</a>
                </div>
            </form>
        </div>
        
        <!-- Ejemplo de plantilla existente -->
        <div class="card">
            <h3 style="margin-bottom: 1.5rem;">🎨 Plantillas SVG Configuradas (Demo)</h3>
            <div class="plantillas-grid">
                <div class="plantilla-card">
                    <div class="plantilla-header">
                        <h4 style="margin: 0; color: #333; font-size: 1.1rem;">
                            Certificado Moderno Demo
                        </h4>
                        <span class="rol-badge rol-participante">
                            Participante
                        </span>
                    </div>
                    
                    <div class="plantilla-preview">
                        <div style="color: #667eea; font-size: 3rem;">🎨</div>
                    </div>
                    
                    <div class="svg-dimensions">
                        📏 <strong>Dimensiones:</strong> 1200px × 850px
                    </div>
                    
                    <div class="variables-info">
                        <strong>🔧 Variables disponibles (10):</strong>
                        <div class="variables-list">
                            <span class="variable-tag variable-required">{{nombres}}</span>
                            <span class="variable-tag variable-required">{{apellidos}}</span>
                            <span class="variable-tag variable-required">{{evento_nombre}}</span>
                            <span class="variable-tag variable-required">{{codigo_verificacion}}</span>
                            <span class="variable-tag variable-required">{{numero_identificacion}}</span>
                            <span class="variable-tag variable-optional">{{fecha_inicio}}</span>
                            <span class="variable-tag variable-optional">{{fecha_fin}}</span>
                            <span class="variable-tag variable-optional">{{entidad_organizadora}}</span>
                            <span class="variable-tag variable-optional">{{modalidad}}</span>
                            <span class="variable-tag variable-optional">{{rol}}</span>
                        </div>
                    </div>
                    
                    <div style="margin: 1rem 0; color: #666; font-size: 0.85rem;">
                        <div><strong>📁 Archivo:</strong> plantilla_demo.svg</div>
                        <div><strong>📅 Creado:</strong> 09/06/2025 16:30</div>
                    </div>
                    
                    <div class="plantilla-actions">
                        <a href="#" class="btn-sm btn-preview">
                            👁️ Vista Previa
                        </a>
                        <a href="#" class="btn-sm btn-download">
                            📥 Descargar
                        </a>
                        <a href="#" class="btn-sm btn-delete" onclick="return confirm('¿Está seguro de eliminar esta plantilla SVG?')">
                            🗑️ Eliminar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mostrar información del archivo SVG seleccionado
        document.getElementById('archivo_plantilla').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const display = document.querySelector('.file-input-display');
            
            if (file) {
                if (file.type === 'image/svg+xml' || file.name.toLowerCase().endsWith('.svg')) {
                    display.innerHTML = `
                        <div class="file-icon">✅</div>
                        <div>
                            <div style="font-weight: 600; color: #28a745;">${file.name}</div>
                            <div style="font-size: 0.9rem; color: #666;">
                                Tamaño: ${(file.size / 1024).toFixed(1)} KB | Tipo: SVG
                            </div>
                            <div style="font-size: 0.8rem; color: #28a745; margin-top: 0.25rem;">
                                ✅ Archivo SVG válido seleccionado
                            </div>
                        </div>
                    `;
                } else {
                    display.innerHTML = `
                        <div class="file-icon">❌</div>
                        <div>
                            <div style="font-weight: 600; color: #dc3545;">${file.name}</div>
                            <div style="font-size: 0.9rem; color: #dc3545;">
                                Error: Solo se permiten archivos SVG
                            </div>
                        </div>
                    `;
                    this.value = ''; // Limpiar la selección
                }
            }
        });
        

        // Validar formulario antes de enviar
        document.querySelector('form').addEventListener('submit', function(e) {
            const archivo = document.getElementById('archivo_plantilla').files[0];
            const nombre = document.getElementById('nombre_plantilla').value.trim();
            const rol = document.getElementById('rol').value;
            
            if (!archivo || !nombre || !rol) {
                e.preventDefault();
                alert('❌ Por favor, complete todos los campos obligatorios:\n• Nombre de la plantilla\n• Rol del participante\n• Archivo SVG');
                return false;
            }
            
            if (!archivo.name.toLowerCase().endsWith('.svg')) {
                e.preventDefault();
                alert('❌ Error de formato:\nSolo se permiten archivos SVG (.svg).\n\n💡 Consejo: Use la plantilla de ejemplo para asegurar compatibilidad.');
                return false;
            }
            
            // Validar tamaño del archivo (máximo 5MB)
            if (archivo.size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert('❌ Archivo demasiado grande:\nEl archivo SVG no puede superar 5MB.\n\n💡 Optimice su SVG para reducir el tamaño.');
                return false;
            }
            
            // Mostrar mensaje de carga
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Procesando SVG...';
            
            // Restaurar botón si hay error (timeout de seguridad)
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 10000);
            
            return true;
        });
        
        // Mejorar la vista previa de las plantillas SVG
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.plantilla-preview svg').forEach(svg => {
                svg.style.maxWidth = '100%';
                svg.style.maxHeight = '150px';
                svg.style.border = '1px solid #dee2e6';
                svg.style.borderRadius = '4px';
                svg.style.background = 'white';
            });
        });
        
        // Destacar variables requeridas vs opcionales
        const style = document.createElement('style');
        style.textContent = `
            .variable-required {
                background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
                animation: pulse 2s infinite;
                position: relative;
            }
            .variable-required::before {
                content: '⚠️';
                position: absolute;
                left: -15px;
                font-size: 10px;
            }
            .variable-optional {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            }
            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.7; }
                100% { opacity: 1; }
            }
        `;
        document.head.appendChild(style);
        
        // Tooltip para variables con información detallada
        document.querySelectorAll('.variable-tag').forEach(tag => {
            const variable = tag.textContent.replace(/[{}]/g, '');
            const isRequired = ['nombres', 'apellidos', 'evento_nombre', 'codigo_verificacion', 'numero_identificacion'].includes(variable);
            
            const tooltips = {
                'nombres': 'Nombre(s) del participante',
                'apellidos': 'Apellido(s) del participante', 
                'numero_identificacion': 'Número de cédula o documento del participante',
                'evento_nombre': 'Nombre completo del evento',
                'codigo_verificacion': 'Código único para verificar autenticidad',
                'fecha_inicio': 'Fecha de inicio del evento',
                'fecha_fin': 'Fecha de finalización del evento',
                'entidad_organizadora': 'Organización que realiza el evento',
                'modalidad': 'Virtual, presencial o híbrida',
                'rol': 'Rol del participante en el evento',
                'lugar': 'Ubicación física o virtual del evento',
                'horas_duracion': 'Duración total en horas académicas',
                'url_verificacion': 'URL completa para verificar el certificado',
                'fecha_generacion': 'Fecha y hora de emisión del certificado',
                'año': 'Año actual',
                'numero_certificado': 'Número secuencial del certificado'
            };
            
            tag.title = `${isRequired ? '🔴 OBLIGATORIO' : '🟢 OPCIONAL'}: ${tooltips[variable] || variable}`;
                
            if (isRequired) {
                tag.style.fontWeight = 'bold';
                tag.classList.add('variable-required');
            } else {
                tag.classList.add('variable-optional');
            }
        });
        
        // Efecto hover mejorado para las tarjetas
        document.querySelectorAll('.plantilla-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
                this.style.boxShadow = '0 20px 40px rgba(102, 126, 234, 0.3)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
                this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            });
        });
        
        // Validación en tiempo real del nombre de plantilla
        document.getElementById('nombre_plantilla').addEventListener('input', function(e) {
            const valor = e.target.value;
            const contador = document.getElementById('nombre-contador');
            
            if (!contador) {
                const contadorElement = document.createElement('div');
                contadorElement.id = 'nombre-contador';
                contadorElement.style.fontSize = '0.8rem';
                contadorElement.style.color = '#666';
                contadorElement.style.marginTop = '0.25rem';
                e.target.parentNode.appendChild(contadorElement);
            }
            
            const length = valor.length;
            const maxLength = 100;
            document.getElementById('nombre-contador').innerHTML = 
                `${length}/${maxLength} caracteres ${length > maxLength ? '❌ Demasiado largo' : length > 50 ? '⚠️' : '✅'}`;
        });
        
        // Auto-guardar borrador del formulario
        const form = document.querySelector('form');
        const inputs = form.querySelectorAll('input, select');
        
        inputs.forEach(input => {
            // Cargar valores guardados
            const savedValue = localStorage.getItem(`plantilla_draft_${input.name}`);
            if (savedValue && input.type !== 'file') {
                input.value = savedValue;
            }
            
            // Guardar cambios
            input.addEventListener('change', function() {
                if (this.type !== 'file') {
                    localStorage.setItem(`plantilla_draft_${this.name}`, this.value);
                }
            });
        });
        
        // Limpiar borrador al enviar exitosamente
        form.addEventListener('submit', function() {
            setTimeout(() => {
                inputs.forEach(input => {
                    localStorage.removeItem(`plantilla_draft_${input.name}`);
                });
            }, 1000);
        });
        
        console.log('🎨 Sistema de plantillas SVG inicializado correctamente');
        console.log('📋 Todas las funciones de validación y descarga están operativas');
    </script>
</body>
</html>