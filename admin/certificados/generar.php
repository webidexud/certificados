<?php
// admin/certificados/generar.php - VERSIÓN MASIVA FINAL
require_once '../../config/config.php';
require_once '../../includes/funciones.php';
require_once '../../includes/funciones_svg.php';

verificarAutenticacion();

$error = '';
$success = '';
$estadisticas = [];
$eventos = [];
$participantes = [];
$evento_seleccionado = null;

// Obtener lista de eventos
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM eventos ORDER BY fecha_inicio DESC");
    $stmt->execute();
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

// Obtener evento seleccionado
$evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : 0;

if ($evento_id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
        $stmt->execute([$evento_id]);
        $evento_seleccionado = $stmt->fetch();
        
        if ($evento_seleccionado) {
            // Obtener participantes del evento con todos los datos necesarios
            $stmt = $db->prepare("
                SELECT p.*, 
                       e.nombre as evento_nombre,
                       e.fecha_inicio, e.fecha_fin, e.entidad_organizadora, 
                       e.modalidad, e.lugar, e.horas_duracion, e.descripcion,
                       c.id as certificado_id,
                       c.codigo_verificacion,
                       c.fecha_generacion,
                       c.tipo_archivo,
                       c.estado as certificado_estado,
                       (SELECT COUNT(*) FROM certificados c2 WHERE c2.participante_id = p.id) as tiene_certificado
                FROM participantes p
                JOIN eventos e ON p.evento_id = e.id
                LEFT JOIN certificados c ON p.id = c.participante_id
                WHERE p.evento_id = ?
                ORDER BY p.apellidos, p.nombres
            ");
            $stmt->execute([$evento_id]);
            $participantes = $stmt->fetchAll();
            
            // Calcular estadísticas
            $total_participantes = count($participantes);
            $con_certificado = 0;
            $sin_certificado = 0;
            $por_rol = [];
            
            foreach ($participantes as $participante) {
                $rol = $participante['rol'];
                if (!isset($por_rol[$rol])) {
                    $por_rol[$rol] = ['total' => 0, 'con_certificado' => 0, 'sin_certificado' => 0];
                }
                $por_rol[$rol]['total']++;
                
                if ($participante['certificado_id']) {
                    $con_certificado++;
                    $por_rol[$rol]['con_certificado']++;
                } else {
                    $sin_certificado++;
                    $por_rol[$rol]['sin_certificado']++;
                }
            }
            
            $estadisticas = [
                'total_participantes' => $total_participantes,
                'con_certificado' => $con_certificado,
                'sin_certificado' => $sin_certificado,
                'porcentaje_completado' => $total_participantes > 0 ? round(($con_certificado / $total_participantes) * 100, 1) : 0,
                'por_rol' => $por_rol
            ];
        }
    } catch (Exception $e) {
        $error = "Error al cargar datos del evento: " . $e->getMessage();
    }
}

// PROCESAR ACCIONES MASIVAS - USANDO EL MISMO ALGORITMO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $participantes_seleccionados = $_POST['participantes'] ?? [];
    $evento_id_post = $_POST['evento_id'] ?? null;
    
    if (empty($participantes_seleccionados) || !$evento_id_post) {
        $error = 'Debe seleccionar al menos un participante';
    } else {
        try {
            if ($accion === 'generar_certificados') {
                $generados = 0;
                $errores = 0;
                $mensajes_error = [];
                
                foreach ($participantes_seleccionados as $participante_id) {
                    try {
                        // USAR EXACTAMENTE LA MISMA LÓGICA DEL ARCHIVO INDIVIDUAL
                        
                        // Obtener datos completos del participante - MISMA CONSULTA
                        $stmt = $db->prepare("
                            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion, e.descripcion,
                                   (SELECT COUNT(*) FROM certificados c WHERE c.participante_id = p.id) as tiene_certificado
                            FROM participantes p 
                            JOIN eventos e ON p.evento_id = e.id 
                            WHERE p.id = ?
                        ");
                        $stmt->execute([$participante_id]);
                        $participante = $stmt->fetch();
                        
                        if (!$participante) {
                            $errores++;
                            $mensajes_error[] = "Participante ID $participante_id no encontrado";
                            continue;
                        }
                        
                        // Verificar si ya tiene certificado - MISMA VALIDACIÓN
                        if ($participante['tiene_certificado'] > 0) {
                            $errores++;
                            $mensajes_error[] = $participante['nombres'] . ' ' . $participante['apellidos'] . ' ya tiene certificado';
                            continue;
                        }
                        
                        // BUSCAR PLANTILLA PARA EL ROL DEL PARTICIPANTE - MISMA CONSULTA
                        $stmt = $db->prepare("
                            SELECT * FROM plantillas_certificados 
                            WHERE evento_id = ? AND (rol = ? OR rol = 'General') 
                            ORDER BY CASE WHEN rol = ? THEN 1 ELSE 2 END 
                            LIMIT 1
                        ");
                        $stmt->execute([$participante['evento_id'], $participante['rol'], $participante['rol']]);
                        $plantilla = $stmt->fetch();
                        
                        if (!$plantilla) {
                            $errores++;
                            $mensajes_error[] = 'No hay plantilla configurada para el rol "' . $participante['rol'] . '" en este evento';
                            continue;
                        }
                        
                        // Verificar que el archivo de plantilla existe - MISMA VALIDACIÓN
                        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
                        if (!file_exists($ruta_plantilla)) {
                            $errores++;
                            $mensajes_error[] = 'El archivo de plantilla no existe para ' . $participante['nombres'] . ' ' . $participante['apellidos'];
                            continue;
                        }
                        
                        // GENERAR CERTIFICADO CON LA PLANTILLA - MISMA FUNCIÓN
                        $resultado = generarCertificadoConPlantilla($participante, $plantilla);
                        
                        if ($resultado['success']) {
                            $generados++;
                        } else {
                            $errores++;
                            $mensajes_error[] = $participante['nombres'] . ' ' . $participante['apellidos'] . ': ' . $resultado['error'];
                        }
                        
                    } catch (Exception $e) {
                        $errores++;
                        $mensajes_error[] = "Error con participante ID $participante_id: " . $e->getMessage();
                    }
                }
                
                // Construir mensaje de resultado
                $mensaje_final = '';
                if ($generados > 0) {
                    $mensaje_final = "Se generaron $generados certificados exitosamente";
                }
                if ($errores > 0) {
                    if ($mensaje_final) $mensaje_final .= ". ";
                    $mensaje_final .= "Hubo $errores errores";
                    if (!empty($mensajes_error)) {
                        $mensaje_final .= ": " . implode('; ', array_slice($mensajes_error, 0, 3));
                        if (count($mensajes_error) > 3) {
                            $mensaje_final .= " y " . (count($mensajes_error) - 3) . " errores más";
                        }
                    }
                }
                
                if ($generados > 0) {
                    $success = $mensaje_final;
                } else {
                    $error = $mensaje_final ?: 'No se pudieron generar certificados';
                }
                
            } elseif ($accion === 'eliminar_certificados') {
                // Eliminar certificados
                $placeholders = str_repeat('?,', count($participantes_seleccionados) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM certificados WHERE participante_id IN ($placeholders)");
                $stmt->execute($participantes_seleccionados);
                $eliminados = $stmt->rowCount();
                
                if ($eliminados > 0) {
                    $success = "Se eliminaron $eliminados certificados exitosamente";
                } else {
                    $error = "No se encontraron certificados para eliminar";
                }
            }
            
        } catch (Exception $e) {
            $error = 'Error al procesar la acción: ' . $e->getMessage();
        }
    }
    
    // Recargar página para mostrar cambios
    $redirect_url = $_SERVER['REQUEST_URI'];
    if ($success) {
        $_SESSION['success_mensaje'] = $success;
    }
    if ($error) {
        $_SESSION['error_mensaje'] = $error;
    }
    
    header("Location: $redirect_url");
    exit;
}

// Mostrar mensajes de sesión
if (isset($_SESSION['success_mensaje'])) {
    $success = $_SESSION['success_mensaje'];
    unset($_SESSION['success_mensaje']);
}
if (isset($_SESSION['error_mensaje'])) {
    $error = $_SESSION['error_mensaje'];
    unset($_SESSION['error_mensaje']);
}

// COPIAR EXACTAMENTE LA FUNCIÓN DEL ARCHIVO INDIVIDUAL
function generarCertificadoConPlantilla($participante, $plantilla) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Leer contenido de la plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        $contenido_svg = file_get_contents($ruta_plantilla);
        
        if ($contenido_svg === false) {
            throw new Exception("No se pudo leer la plantilla SVG");
        }
        
        // Preparar datos para reemplazar en la plantilla
        $datos_certificado = [
            '{{nombres}}' => $participante['nombres'],
            '{{apellidos}}' => $participante['apellidos'],
            '{{numero_identificacion}}' => $participante['numero_identificacion'],
            '{{correo_electronico}}' => $participante['correo_electronico'],
            '{{rol}}' => $participante['rol'],
            '{{telefono}}' => $participante['telefono'] ?: '',
            '{{institucion}}' => $participante['institucion'] ?: '',
            
            // Datos del evento
            '{{evento_nombre}}' => $participante['evento_nombre'],
            '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
            '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
            '{{entidad_organizadora}}' => $participante['entidad_organizadora'],
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => $participante['lugar'] ?: 'Virtual',
            '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
            
            // Datos del certificado
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{fecha_emision}}' => date('d/m/Y'),
            '{{año}}' => date('Y'),
            '{{mes}}' => date('m'),
            '{{dia}}' => date('d'),
            
            // URLs y enlaces
            '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion,
            '{{numero_certificado}}' => 'CERT-' . date('Y') . '-' . str_pad($participante['id'], 6, '0', STR_PAD_LEFT),
            
            // Extras
            '{{nombre_completo}}' => $participante['nombres'] . ' ' . $participante['apellidos'],
            '{{iniciales}}' => strtoupper(substr($participante['nombres'], 0, 1) . substr($participante['apellidos'], 0, 1)),
            '{{duracion_texto}}' => ($participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'Duración no especificada'),
            '{{modalidad_completa}}' => 'Modalidad ' . ucfirst($participante['modalidad']),
        ];
        
        // Procesar el SVG con manejo de texto largo
        $svg_procesado = procesarTextoSVG($contenido_svg, $datos_certificado);
        
        // Procesar nombres largos específicamente
        $svg_procesado = procesarNombresLargos($svg_procesado, $participante['nombres'], $participante['apellidos']);
        
        // Procesar eventos largos
        $svg_procesado = procesarEventosLargos($svg_procesado, $participante['evento_nombre']);
        
        // Optimizar SVG para mejor renderizado
        $svg_procesado = optimizarSVGTexto($svg_procesado);
        
        // Generar nombre de archivo
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar SVG procesado
        if (file_put_contents($ruta_completa, $svg_procesado) === false) {
            throw new Exception("No se pudo escribir el archivo SVG");
        }
        
        // Generar hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d'));
        
        // Extraer dimensiones del SVG
        $dimensiones = extraerDimensionesSVG($svg_procesado);
        
        // Insertar en base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, dimensiones, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, 'svg', ?, NOW())
        ");
        
        $resultado_bd = $stmt->execute([
            $participante['id'],
            $participante['evento_id'],
            $codigo_verificacion,
            $nombre_archivo,
            $hash_validacion,
            json_encode($dimensiones)
        ]);
        
        if (!$resultado_bd) {
            throw new Exception("Error al insertar en base de datos");
        }
        
        // Registrar auditoría
        registrarAuditoria('GENERAR_CERTIFICADO_SVG', 'certificados', $db->lastInsertId(), null, [
            'participante_id' => $participante['id'],
            'codigo_verificacion' => $codigo_verificacion,
            'tipo_archivo' => 'svg',
            'plantilla_usada' => $plantilla['nombre_plantilla'],
            'rol' => $participante['rol']
        ]);
        
        return [
            'success' => true,
            'codigo_verificacion' => $codigo_verificacion,
            'tipo' => 'svg',
            'archivo' => $nombre_archivo,
            'plantilla' => $plantilla['nombre_plantilla']
        ];
        
    } catch (Exception $e) {
        error_log("Error generando certificado SVG: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// COPIAR EXACTAMENTE LA FUNCIÓN DEL ARCHIVO INDIVIDUAL
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f4; color: #333; line-height: 1.6; }
        .container { max-width: 1400px; margin: 0 auto; background: white; min-height: 100vh; }
        .header { background: #2c3e50; color: white; padding: 20px 30px; border-bottom: 3px solid #3498db; }
        .header h1 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .breadcrumb { background: #ecf0f1; padding: 12px 30px; border-bottom: 1px solid #ddd; font-size: 14px; }
        .breadcrumb a { color: #3498db; text-decoration: none; margin-right: 8px; }
        .main-content { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #2c3e50; }
        .form-control { width: 100%; max-width: 400px; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; transition: all 0.3s; }
        .form-control:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1); }
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.3s; margin-right: 10px; margin-bottom: 10px; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 8px 16px; font-size: 12px; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 6px; border-left: 4px solid; }
        .alert-success { background-color: #d4edda; border-color: #27ae60; color: #155724; }
        .alert-error { background-color: #f8d7da; border-color: #e74c3c; color: #721c24; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border: 2px solid #ecf0f1; border-radius: 8px; padding: 20px; text-align: center; }
        .stat-number { font-size: 2.5em; font-weight: bold; color: #3498db; margin-bottom: 5px; }
        .stat-label { color: #666; font-size: 14px; }
        .controls-section { background: #f8f9fa; border: 2px solid #ecf0f1; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .selection-controls { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }
        .quick-select-buttons { margin-bottom: 15px; }
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        .participants-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .participants-table th { background: #2c3e50; color: white; padding: 15px 12px; text-align: left; font-weight: 500; font-size: 14px; }
        .participants-table td { padding: 12px; border-bottom: 1px solid #ecf0f1; font-size: 14px; }
        .participants-table tr:hover { background-color: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 500; text-transform: uppercase; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-role { background: #cce4ff; color: #0066cc; }
        .checkbox-cell { width: 40px; text-align: center; }
        .participant-checkbox { width: 18px; height: 18px; cursor: pointer; }
        .empty-state { text-align: center; padding: 60px 20px; color: #666; }
        .empty-state h3 { margin-bottom: 10px; color: #2c3e50; }
        #contadorSeleccionados { font-weight: bold; margin-left: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 Generar Certificados</h1>
            <p>Genere certificados masivamente usando las plantillas SVG configuradas</p>
        </div>
        
        <div class="breadcrumb">
            <a href="../index.php">Inicio</a> &gt; 
            <a href="../certificados/">Certificados</a> &gt; 
            <span>Generar</span>
        </div>
        
        <div class="main-content">
            <!-- Mensajes -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✅ <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Selector de evento -->
            <div class="form-group">
                <label for="evento_selector">Seleccionar Evento:</label>
                <form method="GET" style="display: inline;">
                    <select name="evento_id" id="evento_selector" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Seleccione un evento --</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>" 
                                    <?php echo ($evento_id == $evento['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($evento['nombre']); ?> 
                                (<?php echo date('d/m/Y', strtotime($evento['fecha_inicio'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            
            <?php if ($evento_seleccionado && !empty($participantes)): ?>
                <!-- Estadísticas -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['total_participantes']; ?></div>
                        <div class="stat-label">Total Participantes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #27ae60;"><?php echo $estadisticas['con_certificado']; ?></div>
                        <div class="stat-label">Con Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #e74c3c;"><?php echo $estadisticas['sin_certificado']; ?></div>
                        <div class="stat-label">Sin Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #f39c12;"><?php echo $estadisticas['porcentaje_completado']; ?>%</div>
                        <div class="stat-label">Progreso</div>
                    </div>
                </div>
                
                <!-- FORMULARIO ÚNICO CON TODO INCLUIDO -->
                <form method="POST" id="formAccionMasiva" action="">
                    <input type="hidden" name="evento_id" value="<?php echo $evento_seleccionado['id']; ?>">
                    
                    <!-- Controles de selección y acciones -->
                    <div class="controls-section">
                        <h3>Acciones Masivas</h3>
                        <div class="quick-select-buttons">
                            <button type="button" class="btn btn-sm btn-primary" onclick="seleccionarTodos()">
                                Seleccionar Todos
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="deseleccionarTodos()">
                                Deseleccionar Todos
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="seleccionarSinCertificado()">
                                Solo Sin Certificado
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" onclick="seleccionarConCertificado()">
                                Solo Con Certificado
                            </button>
                        </div>
                        
                        <div>
                            <span id="contadorSeleccionados">0 participantes seleccionados</span>
                        </div>
                        
                        <div class="selection-controls">
                            <button type="submit" name="accion" value="generar_certificados" class="btn btn-success" 
                                    onclick="return confirmarAccion('generar')">
                                🎓 Generar Certificados Seleccionados
                            </button>
                            <button type="submit" name="accion" value="eliminar_certificados" class="btn btn-danger" 
                                    onclick="return confirmarAccion('eliminar')">
                                🗑️ Eliminar Certificados Seleccionados
                            </button>
                        </div>
                    </div>
                    
                    <!-- Tabla de participantes DENTRO del formulario -->
                    <div class="table-responsive">
                        <table class="participants-table">
                            <thead>
                                <tr>
                                    <th class="checkbox-cell">
                                        <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                                    </th>
                                    <th>Participante</th>
                                    <th>Identificación</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Código Verificación</th>
                                    <th>Fecha Generación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participantes as $participante): ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" 
                                                   name="participantes[]" 
                                                   value="<?php echo $participante['id']; ?>"
                                                   class="participante-checkbox"
                                                   data-tiene-certificado="<?php echo $participante['certificado_id'] ? 'true' : 'false'; ?>"
                                                   onchange="actualizarContador()">
                                        </td>
                                        <td>
                                            <div style="font-weight: 500;">
                                                <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #666;">
                                                <?php echo htmlspecialchars($participante['correo_electronico']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($participante['numero_identificacion']); ?></td>
                                        <td>
                                            <span class="badge badge-role">
                                                <?php echo htmlspecialchars($participante['rol']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($participante['certificado_id']): ?>
                                                <span class="badge badge-success">✅ Generado</span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">⏳ Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($participante['codigo_verificacion']): ?>
                                                <code style="background: #f7fafc; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.8rem;">
                                                    <?php echo htmlspecialchars($participante['codigo_verificacion']); ?>
                                                </code>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($participante['fecha_generacion']): ?>
                                                <?php echo date('d/m/Y H:i', strtotime($participante['fecha_generacion'])); ?>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                
            <?php elseif ($evento_seleccionado && empty($participantes)): ?>
                <div class="empty-state">
                    <h3>No hay participantes en este evento</h3>
                    <p>Para generar certificados, primero debe cargar los participantes del evento.</p>
                    <a href="../participantes/cargar.php?evento_id=<?php echo $evento_seleccionado['id']; ?>" class="btn btn-primary">
                        📥 Cargar Participantes
                    </a>
                </div>
                
            <?php elseif (!$evento_seleccionado): ?>
                <div class="empty-state">
                    <h3>Seleccione un evento</h3>
                    <p>Para comenzar la gestión de certificados, seleccione un evento de la lista desplegable.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        let participantesSeleccionados = 0;
        
        function seleccionarTodos() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = true);
            document.getElementById('selectAll').checked = true;
            actualizarContador();
        }
        
        function deseleccionarTodos() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = false);
            document.getElementById('selectAll').checked = false;
            actualizarContador();
        }
        
        function seleccionarSinCertificado() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = checkbox.dataset.tieneCertificado === 'false';
            });
            actualizarContador();
        }
        
        function seleccionarConCertificado() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = checkbox.dataset.tieneCertificado === 'true';
            });
            actualizarContador();
        }
        
        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            actualizarContador();
        }
        
        function actualizarContador() {
            const checkboxes = document.querySelectorAll('.participante-checkbox:checked');
            participantesSeleccionados = checkboxes.length;
            
            const contador = document.getElementById('contadorSeleccionados');
            if (participantesSeleccionados === 0) {
                contador.textContent = '0 participantes seleccionados';
                contador.style.color = '#999';
            } else {
                contador.textContent = `${participantesSeleccionados} participante${participantesSeleccionados > 1 ? 's' : ''} seleccionado${participantesSeleccionados > 1 ? 's' : ''}`;
                contador.style.color = '#4299e1';
            }
            
            // Actualizar estado del checkbox principal
            const totalCheckboxes = document.querySelectorAll('.participante-checkbox').length;
            const selectAllCheckbox = document.getElementById('selectAll');
            
            if (participantesSeleccionados === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (participantesSeleccionados === totalCheckboxes) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }
        
        function confirmarAccion(accion) {
            if (participantesSeleccionados === 0) {
                alert('Debe seleccionar al menos un participante.');
                return false;
            }
            
            let mensaje = '';
            if (accion === 'generar') {
                mensaje = `¿Está seguro de generar certificados para ${participantesSeleccionados} participante${participantesSeleccionados > 1 ? 's' : ''}?`;
            } else if (accion === 'eliminar') {
                mensaje = `¿Está seguro de eliminar los certificados de ${participantesSeleccionados} participante${participantesSeleccionados > 1 ? 's' : ''}?\n\nEsta acción no se puede deshacer.`;
            }
            
            return confirm(mensaje);
        }
        
        function mostrarLoading(boton, texto) {
            boton.disabled = true;
            boton.innerHTML = `${texto}...`;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar contador
            actualizarContador();
            
            // Auto-ocultar mensajes después de 5 segundos
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.3s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
            
            // Manejar envío del formulario de acciones masivas
            const form = document.getElementById('formAccionMasiva');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const action = e.submitter ? e.submitter.value : '';
                    const boton = e.submitter;
                    
                    // Validar que hay participantes seleccionados
                    const checkboxes = document.querySelectorAll('.participante-checkbox:checked');
                    if (checkboxes.length === 0) {
                        alert('Debe seleccionar al menos un participante.');
                        e.preventDefault();
                        return false;
                    }
                    
                    if (action === 'generar_certificados') {
                        mostrarLoading(boton, '🎓 Generando certificados');
                    } else if (action === 'eliminar_certificados') {
                        mostrarLoading(boton, '🗑️ Eliminando certificados');
                    }
                });
            }
        });
    </script>
</body>
</html>