<?php
// admin/eventos/plantillas.php - VERSIÓN FUNCIONAL LIGERA
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : 0;
$error = '';
$success = '';

// Manejar mensajes de redirección
if (isset($_GET['msg']) && isset($_GET['text'])) {
    if ($_GET['msg'] === 'success') {
        $success = urldecode($_GET['text']);
    } elseif ($_GET['msg'] === 'error') {
        $error = urldecode($_GET['text']);
    }
}

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
        $error = 'Complete todos los campos obligatorios';
    } elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir archivo: ' . $archivo['error'];
    } else {
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        
        if ($extension !== 'svg') {
            $error = 'Solo archivos SVG permitidos';
        } else {
            try {
                // Leer contenido SVG
                $contenido_svg = file_get_contents($archivo['tmp_name']);
                
                if (empty($contenido_svg)) {
                    throw new Exception("Archivo vacío");
                }
                
                if (strpos($contenido_svg, '<svg') === false) {
                    throw new Exception("No es un SVG válido");
                }
                
                // Variables obligatorias
                $variables_requeridas = [
                    '{{nombres}}', 
                    '{{apellidos}}', 
                    '{{evento_nombre}}', 
                    '{{codigo_verificacion}}',
                    '{{numero_identificacion}}'
                ];
                
                $variables_faltantes = [];
                foreach ($variables_requeridas as $variable) {
                    if (strpos($contenido_svg, $variable) === false) {
                        $variables_faltantes[] = $variable;
                    }
                }
                
                if (!empty($variables_faltantes)) {
                    $error = 'Variables obligatorias faltantes: ' . implode(', ', $variables_faltantes);
                } else {
                    // Generar nombre único
                    $nombre_archivo = 'plantilla_' . $evento_id . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $rol) . '_' . time() . '.svg';
                    $ruta_destino = TEMPLATE_PATH . $nombre_archivo;
                    
                    // Limpiar SVG
                    $contenido_svg = limpiarContenidoSVG($contenido_svg);
                    $dimensiones = extraerDimensionesSVG($contenido_svg);
                    
                    // Crear directorio
                    if (!is_dir(TEMPLATE_PATH)) {
                        mkdir(TEMPLATE_PATH, 0755, true);
                    }
                    
                    // Guardar archivo
                    if (file_put_contents($ruta_destino, $contenido_svg) === false) {
                        throw new Exception("Error al guardar SVG");
                    }
                    
                    // Verificar si existe plantilla para este rol
                    $stmt = $db->prepare("SELECT id FROM plantillas_certificados WHERE evento_id = ? AND rol = ?");
                    $stmt->execute([$evento_id, $rol]);
                    $plantilla_existente = $stmt->fetch();
                    
                    if ($plantilla_existente) {
                        // Actualizar
                        $stmt = $db->prepare("UPDATE plantillas_certificados SET nombre_plantilla = ?, archivo_plantilla = ?, ancho = ?, alto = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$nombre_plantilla, $nombre_archivo, $dimensiones['ancho'], $dimensiones['alto'], $plantilla_existente['id']]);
                        $success = 'Plantilla actualizada: ' . $rol;
                    } else {
                        // Insertar nueva
                        $stmt = $db->prepare("INSERT INTO plantillas_certificados (evento_id, rol, nombre_plantilla, archivo_plantilla, ancho, alto, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$evento_id, $rol, $nombre_plantilla, $nombre_archivo, $dimensiones['ancho'], $dimensiones['alto']]);
                        $success = 'Plantilla creada: ' . $rol;
                    }
                    
                    // Recargar plantillas
                    $stmt = $db->prepare("SELECT * FROM plantillas_certificados WHERE evento_id = ? ORDER BY rol, created_at DESC");
                    $stmt->execute([$evento_id]);
                    $plantillas = $stmt->fetchAll();
                }
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Eliminar plantilla
if (isset($_GET['eliminar']) && !empty($_GET['eliminar'])) {
    $plantilla_id = intval($_GET['eliminar']);
    
    if ($plantilla_id > 0) {
        try {
            // Primero verificar si la plantilla existe (sin restricción de evento)
            $stmt = $db->prepare("SELECT * FROM plantillas_certificados WHERE id = ?");
            $stmt->execute([$plantilla_id]);
            $plantilla_eliminar = $stmt->fetch();
            
            if ($plantilla_eliminar) {
                // Verificar que pertenece al evento actual
                if ($plantilla_eliminar['evento_id'] != $evento_id) {
                    $error = 'No tiene permisos para eliminar esta plantilla';
                } else {
                    // Eliminar archivo físico si existe
                    $ruta_archivo = TEMPLATE_PATH . $plantilla_eliminar['archivo_plantilla'];
                    if (file_exists($ruta_archivo)) {
                        @unlink($ruta_archivo); // @ para evitar warnings si no se puede eliminar
                    }
                    
                    // Eliminar de base de datos
                    $stmt = $db->prepare("DELETE FROM plantillas_certificados WHERE id = ?");
                    $resultado = $stmt->execute([$plantilla_id]);
                    
                    if ($resultado) {
                        $success = 'Plantilla eliminada correctamente: ' . $plantilla_eliminar['nombre_plantilla'];
                    } else {
                        $error = 'Error al eliminar plantilla de la base de datos';
                    }
                }
            } else {
                $error = 'La plantilla ya no existe o fue eliminada previamente';
            }
            
            // Siempre recargar plantillas para mostrar estado actual
            $stmt = $db->prepare("SELECT * FROM plantillas_certificados WHERE evento_id = ? ORDER BY rol, created_at DESC");
            $stmt->execute([$evento_id]);
            $plantillas = $stmt->fetchAll();
            
        } catch (Exception $e) {
            $error = "Error al eliminar plantilla: " . $e->getMessage();
        }
    } else {
        $error = 'ID de plantilla inválido';
    }
    
    // Redireccionar para limpiar URL y evitar eliminación accidental en refresh
    $redirect_url = "plantillas.php?evento_id=" . $evento_id;
    if ($success) {
        $redirect_url .= "&msg=success&text=" . urlencode($success);
    } elseif ($error) {
        $redirect_url .= "&msg=error&text=" . urlencode($error);
    }
    header("Location: " . $redirect_url);
    exit;
}

// Funciones auxiliares
function limpiarContenidoSVG($contenido_svg) {
    $contenido_svg = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $contenido_svg);
    $contenido_svg = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $contenido_svg);
    
    if (strpos($contenido_svg, '<?xml') === false) {
        $contenido_svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $contenido_svg;
    }
    
    return $contenido_svg;
}

function extraerDimensionesSVG($contenido_svg) {
    $ancho = 1200;
    $alto = 850;
    
    if (preg_match('/width=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $ancho = intval($matches[1]);
    }
    
    if (preg_match('/height=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $alto = intval($matches[1]);
    }
    
    if (preg_match('/viewBox=["\']([^"\']+)["\']/', $contenido_svg, $matches)) {
        $viewBox = explode(' ', $matches[1]);
        if (count($viewBox) >= 4) {
            $ancho = intval($viewBox[2]);
            $alto = intval($viewBox[3]);
        }
    }
    
    return ['ancho' => $ancho, 'alto' => $alto];
}

if (!function_exists('formatearRol')) {
    function formatearRol($rol) {
        return ucfirst(strtolower($rol));
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plantillas - <?php echo htmlspecialchars($evento['nombre']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f8fffe 0%, #e8f7f5 100%);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 20px auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 120, 135, 0.1);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .header {
            background: linear-gradient(135deg, #007887 0%, #3db8ab 50%, #68d6ca 100%);
            color: white;
            padding: 40px 30px;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        .universidad-logo {
            text-align: right;
            margin-bottom: 20px;
            font-size: 12px;
            opacity: 0.9;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 2;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.95;
            position: relative;
            z-index: 2;
        }
        
        .nav {
            background: rgba(104, 214, 202, 0.1);
            padding: 20px 30px;
            border-bottom: 1px solid rgba(104, 214, 202, 0.2);
        }
        
        .nav a {
            display: inline-block;
            margin-right: 20px;
            color: #007887;
            text-decoration: none;
            font-weight: 500;
            padding: 12px 20px;
            border-radius: 25px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #007887 0%, #3db8ab 100%);
            transition: left 0.3s ease;
            z-index: -1;
        }
        
        .nav a:hover::before {
            left: 0;
        }
        
        .nav a:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 120, 135, 0.3);
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .alert {
            padding: 20px 25px;
            margin: 25px 0;
            border-radius: 15px;
            border: none;
            position: relative;
            overflow: hidden;
        }
        
        .alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: currentColor;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fef2f2 0%, #fdf2f8 100%);
            color: #991b1b;
            border-left: 5px solid #e63946;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            color: #166534;
            border-left: 5px solid #57cc99;
        }
        
        .section-title {
            color: #007887;
            font-size: 1.8rem;
            font-weight: 400;
            margin: 40px 0 25px 0;
            padding-bottom: 15px;
            border-bottom: 3px solid #68d6ca;
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 60px;
            height: 3px;
            background: #007887;
        }
        
        .help {
            background: linear-gradient(135deg, #007887 0%, #07796b 50%, #3db8ab 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin: 30px 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0, 120, 135, 0.2);
        }
        
        .help::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.7; }
            50% { transform: scale(1.1); opacity: 0.3; }
        }
        
        .help h3 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            font-weight: 400;
            position: relative;
            z-index: 2;
        }
        
        .help p {
            margin-bottom: 15px;
            line-height: 1.8;
            position: relative;
            z-index: 2;
        }
        
        .highlight {
            background: rgba(255, 255, 255, 0.2);
            padding: 3px 8px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .form-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8fffe 100%);
            padding: 35px;
            border-radius: 20px;
            margin: 30px 0;
            border: 2px solid rgba(104, 214, 202, 0.1);
            box-shadow: 0 10px 30px rgba(0, 120, 135, 0.05);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #3b4044;
            font-size: 1rem;
        }
        
        .required {
            color: #e63946;
            font-weight: bold;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #007887;
            box-shadow: 0 0 0 4px rgba(0, 120, 135, 0.1);
            transform: translateY(-2px);
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            margin: 8px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-size: 1rem;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s ease, height 0.3s ease;
        }
        
        .btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #007887 0%, #3db8ab 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(0, 120, 135, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0, 120, 135, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #3db8ab 0%, #68d6ca 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(61, 184, 171, 0.3);
        }
        
        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(61, 184, 171, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e63946 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(230, 57, 70, 0.3);
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(230, 57, 70, 0.4);
        }
        
        .plantilla-item {
            background: linear-gradient(135deg, #ffffff 0%, #f8fffe 100%);
            border: 2px solid transparent;
            padding: 30px;
            margin: 25px 0;
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .plantilla-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(104, 214, 202, 0.1) 0%, rgba(61, 184, 171, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .plantilla-item:hover {
            border-color: #68d6ca;
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(104, 214, 202, 0.2);
        }
        
        .plantilla-item:hover::before {
            opacity: 1;
        }
        
        .plantilla-header {
            font-weight: 600;
            font-size: 1.4rem;
            margin-bottom: 15px;
            color: #007887;
            position: relative;
            z-index: 2;
        }
        
        .plantilla-info {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.7;
            position: relative;
            z-index: 2;
        }
        
        .variables {
            background: linear-gradient(135deg, rgba(104, 214, 202, 0.1) 0%, rgba(61, 184, 171, 0.1) 100%);
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            border: 1px solid rgba(104, 214, 202, 0.2);
            position: relative;
            z-index: 2;
        }
        
        .variable {
            background: linear-gradient(135deg, #007887 0%, #3db8ab 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            margin: 4px;
            display: inline-block;
            font-size: 0.85rem;
            font-family: 'Courier New', monospace;
            font-weight: 500;
            box-shadow: 0 4px 10px rgba(0, 120, 135, 0.2);
            transition: transform 0.2s ease;
        }
        
        .variable:hover {
            transform: scale(1.05);
        }
        
        pre {
            background: linear-gradient(135deg, #f8fffe 0%, #ecfdf5 100%);
            padding: 25px;
            border: 2px solid #68d6ca;
            border-radius: 15px;
            overflow-x: auto;
            font-size: 0.9rem;
            line-height: 1.5;
            box-shadow: inset 0 2px 10px rgba(104, 214, 202, 0.1);
        }
        
        .status-ok {
            color: #57cc99;
            font-weight: bold;
        }
        
        .status-error {
            color: #e63946;
            font-weight: bold;
        }
        
        /* Animaciones adicionales */
        .container {
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .plantilla-item {
            animation: fadeInUp 0.6s ease-out;
            animation-fill-mode: both;
        }
        
        .plantilla-item:nth-child(1) { animation-delay: 0.1s; }
        .plantilla-item:nth-child(2) { animation-delay: 0.2s; }
        .plantilla-item:nth-child(3) { animation-delay: 0.3s; }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .header {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .content {
                padding: 30px 20px;
            }
            
            .form-section {
                padding: 25px 20px;
            }
            
            .nav a {
                display: block;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="universidad-logo" style="text-align: right; margin-bottom: 10px; color: rgba(255,255,255,0.9); font-size: 12px;">
                UNIVERSIDAD DISTRITAL FRANCISCO JOSÉ DE CALDAS<br>
                Sistema de Gestión de Proyectos y Oficina de Extensión (SGPOE)
            </div>
            <h1>🎨 Plantillas SVG - <?php echo htmlspecialchars($evento['nombre']); ?></h1>
            <p><strong>Evento:</strong> <?php echo htmlspecialchars($evento['nombre']); ?> | <strong>Fechas:</strong> <?php echo date('d/m/Y', strtotime($evento['fecha_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($evento['fecha_fin'])); ?></p>
        </div>
        
        <div class="nav">
            <a href="../index.php">Dashboard</a>
            <a href="listar.php">← Volver a Eventos</a>
            <a href="../certificados/generar.php">Generar Certificados</a>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>Error:</strong> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Éxito:</strong> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <div class="help">
            <h3>🎯 Guía para Plantillas SVG - IDEXUD</h3>
            <p><strong class="highlight">Variables Obligatorias:</strong> {{nombres}}, {{apellidos}}, {{evento_nombre}}, {{codigo_verificacion}}, {{numero_identificacion}}</p>
            <p><strong class="highlight">Variables Opcionales:</strong> {{fecha_inicio}}, {{fecha_fin}}, {{rol}}, {{entidad_organizadora}}, {{modalidad}}, {{lugar}}, {{numero_certificado}}, {{url_verificacion}}</p>
            <p><strong>📐 Dimensiones recomendadas:</strong> 1200x850px (horizontal) siguiendo estándares institucionales</p>
            <p><strong>🎨 Colores institucionales:</strong> Use la paleta oficial SGPOE (Azul Petróleo #007887, Verde Petróleo #07796b, Turquesa #3db8ab, #68d6ca)</p>
        </div>
        
        <!-- Formulario de subida -->
        <h2>Subir Nueva Plantilla SVG</h2>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="nombre_plantilla">Nombre de la Plantilla *</label>
                <input type="text" id="nombre_plantilla" name="nombre_plantilla" required>
            </div>
            
            <div class="form-group">
                <label for="rol">Rol del Participante *</label>
                <select id="rol" name="rol" required>
                    <option value="">Seleccionar rol</option>
                    <option value="Participante">Participante</option>
                    <option value="Ponente">Ponente</option>
                    <option value="Organizador">Organizador</option>
                    <option value="Moderador">Moderador</option>
                    <option value="Asistente">Asistente</option>
                    <option value="Conferencista">Conferencista</option>
                    <option value="Instructor">Instructor</option>
                    <option value="General">General (todos los roles)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="archivo_plantilla">Archivo SVG *</label>
                <input type="file" id="archivo_plantilla" name="archivo_plantilla" accept=".svg" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Subir Plantilla</button>
        </form>
        
        <!-- Lista de plantillas -->
        <h2>Plantillas Configuradas</h2>
        
        <?php if (empty($plantillas)): ?>
            <p>No hay plantillas configuradas para este evento.</p>
        <?php else: ?>
            <?php foreach ($plantillas as $plantilla): ?>
                <div class="plantilla-item">
                    <div class="plantilla-header">
                        <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?>
                        <span style="background: #007bff; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">
                            <?php echo formatearRol($plantilla['rol']); ?>
                        </span>
                    </div>
                    
                    <div class="plantilla-info">
                        <strong>Archivo:</strong> <?php echo $plantilla['archivo_plantilla']; ?> | 
                        <strong>Dimensiones:</strong> <?php echo $plantilla['ancho']; ?>x<?php echo $plantilla['alto']; ?>px | 
                        <strong>Creado:</strong> <?php echo date('d/m/Y H:i', strtotime($plantilla['created_at'])); ?>
                        <?php if ($plantilla['updated_at'] != $plantilla['created_at']): ?>
                            | <strong>Actualizado:</strong> <?php echo date('d/m/Y H:i', strtotime($plantilla['updated_at'])); ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php 
                    $archivo_existe = file_exists(TEMPLATE_PATH . $plantilla['archivo_plantilla']);
                    ?>
                    
                    <?php if (!$archivo_existe): ?>
                        <div class="alert alert-error">
                            Archivo SVG no encontrado en el servidor
                        </div>
                    <?php endif; ?>
                    
                    <div class="variables">
                        <strong>Variables soportadas:</strong>
                        <span class="variable">nombres</span>
                        <span class="variable">apellidos</span>
                        <span class="variable">evento_nombre</span>
                        <span class="variable">codigo_verificacion</span>
                        <span class="variable">numero_identificacion</span>
                        <span class="variable">fecha_inicio</span>
                        <span class="variable">fecha_fin</span>
                        <span class="variable">rol</span>
                        <span class="variable">entidad_organizadora</span>
                    </div>
                    
                    <div>
                        <?php if ($archivo_existe): ?>
                            <a href="preview_plantilla.php?id=<?php echo $plantilla['id']; ?>" class="btn btn-secondary" target="_blank">
                                👁️ Vista Previa
                            </a>
                            <a href="descargar_plantilla.php?id=<?php echo $plantilla['id']; ?>" class="btn btn-secondary">
                                📥 Descargar
                            </a>
                        <?php else: ?>
                            <span style="color: #dc3545; font-size: 12px;">⚠️ Archivo no disponible</span>
                        <?php endif; ?>
                        <a href="?evento_id=<?php echo $evento_id; ?>&eliminar=<?php echo $plantilla['id']; ?>" 
                           class="btn btn-danger" 
                           onclick="return confirm('¿Está seguro de eliminar la plantilla: <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?>?\n\nEsta acción no se puede deshacer.')">
                            🗑️ Eliminar
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Ejemplo básico -->
        <h2 class="section-title">📋 Ejemplo de Plantilla SVG con Identidad IDEXUD</h2>
        <pre>&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;svg width="1200" height="850" xmlns="http://www.w3.org/2000/svg"&gt;
  &lt;!-- Fondo institucional --&gt;
  &lt;rect width="100%" height="100%" fill="#ffffff" stroke="#007887" stroke-width="3"/&gt;
  
  &lt;!-- Header con colores institucionales --&gt;
  &lt;rect x="0" y="0" width="1200" height="120" fill="url(#gradientHeader)"/&gt;
  &lt;defs&gt;
    &lt;linearGradient id="gradientHeader" x1="0%" y1="0%" x2="100%" y2="0%"&gt;
      &lt;stop offset="0%" style="stop-color:#007887"/&gt;
      &lt;stop offset="100%" style="stop-color:#3db8ab"/&gt;
    &lt;/linearGradient&gt;
  &lt;/defs&gt;
  
  &lt;!-- Título principal --&gt;
  &lt;text x="600" y="200" text-anchor="middle" font-size="32" font-weight="bold" fill="#007887"&gt;
    CERTIFICADO DE PARTICIPACIÓN
  &lt;/text&gt;
  
  &lt;!-- Subtítulo institucional --&gt;
  &lt;text x="600" y="230" text-anchor="middle" font-size="16" fill="#07796b"&gt;
    Universidad Distrital Francisco José de Caldas - IDEXUD
  &lt;/text&gt;
  
  &lt;!-- Nombre del participante --&gt;
  &lt;text x="600" y="320" text-anchor="middle" font-size="28" font-weight="bold" fill="#3db8ab"&gt;
    {{nombres}} {{apellidos}}
  &lt;/text&gt;
  
  &lt;!-- Número de identificación --&gt;
  &lt;text x="600" y="360" text-anchor="middle" font-size="16" fill="#666666"&gt;
    Documento de Identidad: {{numero_identificacion}}
  &lt;/text&gt;
  
  &lt;!-- Evento --&gt;
  &lt;text x="600" y="450" text-anchor="middle" font-size="22" fill="#007887"&gt;
    {{evento_nombre}}
  &lt;/text&gt;
  
  &lt;!-- Fechas del evento --&gt;
  &lt;text x="600" y="500" text-anchor="middle" font-size="18" fill="#07796b"&gt;
    Período: {{fecha_inicio}} - {{fecha_fin}}
  &lt;/text&gt;
  
  &lt;!-- Entidad organizadora --&gt;
  &lt;text x="600" y="640" text-anchor="middle" font-size="16" fill="#3db8ab"&gt;
    {{entidad_organizadora}}
  &lt;/text&gt;
  
  &lt;!-- Footer con código de verificación --&gt;
  &lt;rect x="50" y="750" width="1100" height="60" fill="#68d6ca" opacity="0.3" rx="5"/&gt;
  &lt;text x="600" y="780" text-anchor="middle" font-size="14" fill="#007887"&gt;
    Código de Verificación: {{codigo_verificacion}} | Consulte en: idexud.udistrital.edu.co
  &lt;/text&gt;
&lt;/svg&gt;</pre>
    
        <div class="help">
            <h3>🎨 Recomendaciones de Diseño Institucional</h3>
            <p><strong>✅ Colores oficiales SGPOE:</strong></p>
            <ul style="margin-left: 20px; line-height: 1.8;">
                <li><span class="highlight">Azul Petróleo (#007887)</span> - Color principal, títulos importantes</li>
                <li><span class="highlight">Verde Petróleo (#07796b)</span> - Elementos secundarios, subtítulos</li>
                <li><span class="highlight">Turquesa Medio (#3db8ab)</span> - Nombres, elementos interactivos</li>
                <li><span class="highlight">Turquesa Claro (#68d6ca)</span> - Fondos, elementos de acento</li>
            </ul>
            <p><strong>📐 Tipografía:</strong> Use Roboto (Google Fonts) para consistencia con el sistema</p>
            <p><strong>🏛️ Identidad:</strong> Incluya referencias a "Universidad Distrital" e "IDEXUD" según corresponda</p>
        </div>
    </div>
    
    <script>
        // Validación simple del formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const archivo = document.getElementById('archivo_plantilla').files[0];
            const nombre = document.getElementById('nombre_plantilla').value.trim();
            const rol = document.getElementById('rol').value;
            
            if (!archivo || !nombre || !rol) {
                e.preventDefault();
                alert('Complete todos los campos obligatorios');
                return false;
            }
            
            if (!archivo.name.toLowerCase().endsWith('.svg')) {
                e.preventDefault();
                alert('Solo se permiten archivos SVG');
                return false;
            }
            
            if (archivo.size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert('El archivo es demasiado grande (máximo 5MB)');
                return false;
            }
        });
    </script>
</body>
</html>