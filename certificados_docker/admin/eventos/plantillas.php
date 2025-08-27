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
                    '{{nombre_completo}}',
                    '{{nombres}}',  // Opcional por compatibilidad
                    '{{apellidos}}', // Opcional por compatibilidad
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
            background-color: #f4f4f4;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            min-height: 100vh;
        }
        
        .header {
            background: #2c3e50;
            color: white;
            padding: 20px 30px;
            border-bottom: 3px solid #3498db;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .header .subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .breadcrumb {
            background: #ecf0f1;
            padding: 12px 30px;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: #3498db;
            text-decoration: none;
            margin-right: 8px;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        .content {
            padding: 30px;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid;
        }
        
        .alert-success {
            background-color: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        
        .alert-error {
            background-color: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        
        .section-title {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        
        .card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        .required {
            color: #dc3545;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .btn {
            display: inline-block;
            padding: 10px 16px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 3px;
            text-transform: uppercase;
        }
        
        .badge-primary {
            background: #3498db;
            color: white;
        }
        
        .badge-success {
            background: #28a745;
            color: white;
        }
        
        .badge-danger {
            background: #dc3545;
            color: white;
        }
        
        .info-box {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-box h4 {
            color: #1976d2;
            margin-bottom: 15px;
        }
        
        .info-box ul {
            margin-left: 20px;
        }
        
        .info-box li {
            margin-bottom: 8px;
        }
        
        .variable-tag {
            display: inline-block;
            background: #2c3e50;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            margin: 2px;
        }
        
        .plantilla-item {
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 20px;
            background: white;
        }
        
        .plantilla-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .plantilla-title {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .plantilla-body {
            padding: 20px;
        }
        
        .plantilla-info {
            margin-bottom: 15px;
            color: #666;
            font-size: 14px;
        }
        
        .plantilla-actions {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .code-example {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.4;
            overflow-x: auto;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .content {
                padding: 20px 15px;
            }
            
            .header {
                padding: 15px 20px;
            }
            
            .breadcrumb {
                padding: 10px 20px;
            }
            
            .btn {
                display: block;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .plantilla-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .plantilla-actions {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Gestión de Plantillas SVG</h1>
            <div class="subtitle">
                Evento: <?php echo htmlspecialchars($evento['nombre']); ?> | 
                Fechas: <?php echo date('d/m/Y', strtotime($evento['fecha_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($evento['fecha_fin'])); ?>
            </div>
        </div>
        
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a> /
            <a href="listar.php">Eventos</a> /
            <span>Plantillas</span>
        </div>
        
        <div class="content">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Éxito:</strong> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <!-- Información sobre plantillas -->
            <div class="info-box">
                <h4>📋 Información Importante sobre Plantillas SVG</h4>
                <ul>
                    <li><strong>Variables Obligatorias:</strong> 
                        <li><strong>{{nombre_completo}}</strong> - Nombre completo del participante (nombres + apellidos)</li>
                        <li><strong>{{nombres}}</strong> - Solo nombres (opcional)</li>
                        <li><strong>{{apellidos}}</strong> - Solo apellidos (opcional)</li>
                        <span class="variable-tag">{{evento_nombre}}</span>
                        <span class="variable-tag">{{codigo_verificacion}}</span>
                        <span class="variable-tag">{{numero_identificacion}}</span>
                    </li>
                    <li><strong>Variables Opcionales:</strong> 
                        <span class="variable-tag">{{fecha_inicio}}</span>
                        <span class="variable-tag">{{fecha_fin}}</span>
                        <span class="variable-tag">{{rol}}</span>
                        <span class="variable-tag">{{entidad_organizadora}}</span>
                        <span class="variable-tag">{{modalidad}}</span>
                    </li>
                    <li><strong>Dimensiones recomendadas:</strong> 1200x850px (horizontal)</li>
                    <li><strong>Formato:</strong> Solo archivos SVG válidos</li>
                </ul>
            </div>
            
            <!-- Formulario para subir plantilla -->
            <div class="card">
                <div class="card-header">
                    📤 Subir Nueva Plantilla SVG
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="nombre_plantilla">Nombre de la Plantilla <span class="required">*</span></label>
                            <input type="text" id="nombre_plantilla" name="nombre_plantilla" class="form-control" required placeholder="Ej: Certificado Participante Evento 2024">
                        </div>
                        
                        <div class="form-group">
                            <label for="rol">Rol del Participante <span class="required">*</span></label>
                            <select id="rol" name="rol" class="form-control" required>
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
                            <label for="archivo_plantilla">Archivo SVG <span class="required">*</span></label>
                            <input type="file" id="archivo_plantilla" name="archivo_plantilla" class="form-control" accept=".svg" required>
                            <small style="color: #666; font-size: 12px;">Solo archivos SVG. Tamaño máximo: 5MB</small>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">📤 Subir Plantilla</button>
                    </form>
                </div>
            </div>
            
            <!-- Lista de plantillas existentes -->
            <div class="card">
                <div class="card-header">
                    📋 Plantillas Configuradas (<?php echo count($plantillas); ?>)
                </div>
                <div class="card-body">
                    <?php if (empty($plantillas)): ?>
                        <div class="no-data">
                            <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">📄</div>
                            <h4>No hay plantillas configuradas</h4>
                            <p>Sube la primera plantilla SVG para este evento</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($plantillas as $plantilla): ?>
                            <div class="plantilla-item">
                                <div class="plantilla-header">
                                    <div class="plantilla-title">
                                        📄 <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?>
                                    </div>
                                    <span class="badge badge-primary">
                                        <?php echo formatearRol($plantilla['rol']); ?>
                                    </span>
                                </div>
                                <div class="plantilla-body">
                                    <div class="plantilla-info">
                                        <strong>Archivo:</strong> <?php echo htmlspecialchars($plantilla['archivo_plantilla']); ?> |
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
                                            ⚠️ Archivo SVG no encontrado en el servidor
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                        <strong>Variables soportadas:</strong><br>
                                        <span class="variable-tag">nombres</span>
                                        <span class="variable-tag">apellidos</span>
                                        <span class="variable-tag">evento_nombre</span>
                                        <span class="variable-tag">codigo_verificacion</span>
                                        <span class="variable-tag">numero_identificacion</span>
                                        <span class="variable-tag">fecha_inicio</span>
                                        <span class="variable-tag">fecha_fin</span>
                                        <span class="variable-tag">rol</span>
                                        <span class="variable-tag">entidad_organizadora</span>
                                    </div>
                                    
                                    <div class="plantilla-actions">
                                        <?php if ($archivo_existe): ?>
                                            <a href="preview_plantilla.php?id=<?php echo $plantilla['id']; ?>" class="btn btn-secondary" target="_blank">
                                                👁️ Vista Previa
                                            </a>
                                            <a href="descargar_plantilla.php?id=<?php echo $plantilla['id']; ?>" class="btn btn-success">
                                                📥 Descargar
                                            </a>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Archivo no disponible</span>
                                        <?php endif; ?>
                                        <a href="?evento_id=<?php echo $evento_id; ?>&eliminar=<?php echo $plantilla['id']; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('¿Está seguro de eliminar la plantilla: <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?>?\n\nEsta acción no se puede deshacer.')">
                                            🗑️ Eliminar
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Ejemplo de plantilla SVG -->
            <div class="card">
                <div class="card-header">
                    💡 Ejemplo de Plantilla SVG Básica
                </div>
                <div class="card-body">
                    <p>A continuación se muestra un ejemplo básico de cómo estructurar una plantilla SVG con las variables necesarias:</p>
                    
                    <div class="code-example">
&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;svg width="1200" height="850" xmlns="http://www.w3.org/2000/svg"&gt;
  &lt;!-- Fondo del certificado --&gt;
  &lt;rect width="100%" height="100%" fill="#ffffff" stroke="#2c3e50" stroke-width="3"/&gt;
  
  &lt;!-- Header --&gt;
  &lt;rect x="0" y="0" width="1200" height="100" fill="#2c3e50"/&gt;
  
  &lt;!-- Título principal --&gt;
  &lt;text x="600" y="180" text-anchor="middle" font-size="32" font-weight="bold" fill="#2c3e50"&gt;
    CERTIFICADO DE PARTICIPACIÓN
  &lt;/text&gt;
  
  &lt;!-- Subtítulo institucional --&gt;
  &lt;text x="600" y="220" text-anchor="middle" font-size="16" fill="#666"&gt;
    Universidad Distrital Francisco José de Caldas
  &lt;/text&gt;
  
  &lt;!-- Nombre del participante --&gt;
  &lt;text x="600" y="320" text-anchor="middle" font-size="28" font-weight="bold" fill="#3498db"&gt;
    {{nombres}} {{apellidos}}
  &lt;/text&gt;
  
  &lt;!-- Número de identificación --&gt;
  &lt;text x="600" y="360" text-anchor="middle" font-size="16" fill="#666"&gt;
    Documento de Identidad: {{numero_identificacion}}
  &lt;/text&gt;
  
  &lt;!-- Evento --&gt;
  &lt;text x="600" y="450" text-anchor="middle" font-size="22" fill="#2c3e50"&gt;
    {{evento_nombre}}
  &lt;/text&gt;
  
  &lt;!-- Fechas del evento --&gt;
  &lt;text x="600" y="500" text-anchor="middle" font-size="18" fill="#666"&gt;
    Período: {{fecha_inicio}} - {{fecha_fin}}
  &lt;/text&gt;
  
  &lt;!-- Entidad organizadora --&gt;
  &lt;text x="600" y="600" text-anchor="middle" font-size="16" fill="#2c3e50"&gt;
    {{entidad_organizadora}}
  &lt;/text&gt;
  
  &lt;!-- Footer con código de verificación --&gt;
  &lt;rect x="50" y="750" width="1100" height="60" fill="#ecf0f1" rx="5"/&gt;
  &lt;text x="600" y="780" text-anchor="middle" font-size="14" fill="#2c3e50"&gt;
    Código de Verificación: {{codigo_verificacion}}
  &lt;/text&gt;
&lt;/svg&gt;
                    </div>
                    
                    <div class="info-box">
                        <h4>📝 Recomendaciones para el Diseño</h4>
                        <ul>
                            <li><strong>Colores recomendados:</strong> #2c3e50 (principal), #3498db (acentos), #ffffff (fondo)</li>
                            <li><strong>Fuentes:</strong> Utilice fuentes estándar del sistema para compatibilidad</li>
                            <li><strong>Espaciado:</strong> Mantenga márgenes de al menos 50px desde los bordes</li>
                            <li><strong>Variables:</strong> Asegúrese de incluir todas las variables obligatorias</li>
                            <li><strong>Validación:</strong> Pruebe el SVG en un navegador antes de subirlo</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Validación del formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const archivo = document.getElementById('archivo_plantilla').files[0];
            const nombre = document.getElementById('nombre_plantilla').value.trim();
            const rol = document.getElementById('rol').value;
            
            // Validar campos obligatorios
            if (!archivo || !nombre || !rol) {
                e.preventDefault();
                alert('Por favor complete todos los campos obligatorios');
                return false;
            }
            
            // Validar extensión del archivo
            if (!archivo.name.toLowerCase().endsWith('.svg')) {
                e.preventDefault();
                alert('Solo se permiten archivos SVG');
                return false;
            }
            
            // Validar tamaño del archivo (5MB máximo)
            if (archivo.size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert('El archivo es demasiado grande. Tamaño máximo permitido: 5MB');
                return false;
            }
            
            // Mostrar mensaje de carga
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Subiendo...';
        });
        
        // Mostrar información del archivo seleccionado
        document.getElementById('archivo_plantilla').addEventListener('change', function(e) {
            const archivo = e.target.files[0];
            if (archivo) {
                const info = document.createElement('div');
                info.style.marginTop = '8px';
                info.style.fontSize = '12px';
                info.style.color = '#666';
                info.innerHTML = `Archivo seleccionado: ${archivo.name} (${(archivo.size / 1024).toFixed(1)} KB)`;
                
                // Remover información anterior si existe
                const existingInfo = e.target.parentNode.querySelector('.file-info');
                if (existingInfo) {
                    existingInfo.remove();
                }
                
                info.className = 'file-info';
                e.target.parentNode.appendChild(info);
            }
        });
        
        // Auto-hide alerts después de 5 segundos
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>