<?php
// admin/certificados/generar.php - VERSIÓN CORREGIDA SIN ERRORES SQL
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$error = '';
$success = '';
$eventos = [];
$participantes = [];
$evento_seleccionado = null;

// Mostrar mensajes de sesión
if (isset($_SESSION['success_mensaje'])) {
    $success = $_SESSION['success_mensaje'];
    unset($_SESSION['success_mensaje']);
}
if (isset($_SESSION['error_mensaje'])) {
    $error = $_SESSION['error_mensaje'];
    unset($_SESSION['error_mensaje']);
}

// Obtener lista de eventos
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM eventos WHERE estado = 'activo' ORDER BY fecha_inicio DESC");
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
            // CONSULTA CORREGIDA: Sin GROUP BY problemático
            $stmt = $db->prepare("
                SELECT p.id,
                       p.nombres,
                       p.apellidos,
                       p.numero_identificacion,
                       p.correo_electronico,
                       p.rol,
                       p.telefono,
                       p.institucion,
                       p.evento_id,
                       p.created_at,
                       c.id as certificado_id,
                       c.codigo_verificacion,
                       c.fecha_generacion,
                       c.tipo_archivo,
                       c.estado as certificado_estado,
                       (SELECT COUNT(*) FROM plantillas_certificados pt 
                        WHERE pt.evento_id = p.evento_id 
                        AND (pt.rol = p.rol OR pt.rol = 'General')) as plantillas_disponibles
                FROM participantes p
                LEFT JOIN certificados c ON p.id = c.participante_id
                WHERE p.evento_id = ?
                ORDER BY p.apellidos, p.nombres
            ");
            $stmt->execute([$evento_id]);
            $participantes = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = "Error al cargar datos del evento: " . $e->getMessage();
        error_log("SQL Error: " . $e->getMessage());
    }
}

// PROCESAR ACCIONES MASIVAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $participantes_seleccionados = $_POST['participantes'] ?? [];
    $evento_id_post = intval($_POST['evento_id'] ?? 0);
    
    error_log("DEBUG: Acción recibida: " . $accion);
    error_log("DEBUG: Participantes seleccionados: " . count($participantes_seleccionados));
    error_log("DEBUG: Evento ID: " . $evento_id_post);
    
    if (empty($participantes_seleccionados)) {
        $_SESSION['error_mensaje'] = 'Debe seleccionar al menos un participante';
    } else {
        try {
            if ($accion === 'generar_certificados') {
                $generados = 0;
                $errores = 0;
                $mensajes_error = [];
                
                foreach ($participantes_seleccionados as $participante_id) {
                    $participante_id = intval($participante_id);
                    
                    try {
                        // Verificar que no tenga certificado ya
                        $stmt = $db->prepare("SELECT COUNT(*) FROM certificados WHERE participante_id = ?");
                        $stmt->execute([$participante_id]);
                        if ($stmt->fetchColumn() > 0) {
                            continue; // Ya tiene certificado, saltar
                        }
                        
                        // Obtener datos completos del participante
                        $stmt = $db->prepare("
                            SELECT p.id,
                                   p.nombres,
                                   p.apellidos,
                                   p.numero_identificacion,
                                   p.correo_electronico,
                                   p.rol,
                                   p.telefono,
                                   p.institucion,
                                   p.evento_id,
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
                            $errores++;
                            $mensajes_error[] = "Participante ID $participante_id no encontrado";
                            continue;
                        }
                        
                        // Buscar plantilla para el rol (consulta optimizada)
                        $stmt = $db->prepare("
                            SELECT id, evento_id, rol, archivo_plantilla, variables_disponibles, created_at, updated_at
                            FROM plantillas_certificados 
                            WHERE evento_id = ? 
                            AND (rol = ? OR rol = 'General') 
                            ORDER BY CASE WHEN rol = ? THEN 1 ELSE 2 END 
                            LIMIT 1
                        ");
                        $stmt->execute([$participante['evento_id'], $participante['rol'], $participante['rol']]);
                        $plantilla = $stmt->fetch();
                        
                        if (!$plantilla) {
                            $errores++;
                            $mensajes_error[] = "Sin plantilla para rol: " . $participante['rol'];
                            continue;
                        }
                        
                        // Generar certificado usando la función completa
                        $resultado = generarCertificadoMasivo($participante, $plantilla);
                        
                        if ($resultado['success']) {
                            $generados++;
                            error_log("DEBUG: Certificado generado para participante $participante_id");
                        } else {
                            $errores++;
                            $mensajes_error[] = $resultado['error'];
                        }
                        
                    } catch (Exception $e) {
                        $errores++;
                        $mensajes_error[] = "Error con participante $participante_id: " . $e->getMessage();
                        error_log("DEBUG: Error generando certificado: " . $e->getMessage());
                    }
                }
                
                // Mensaje de resultado
                if ($generados > 0) {
                    $_SESSION['success_mensaje'] = "✅ Se generaron $generados certificados exitosamente";
                    if ($errores > 0) {
                        $_SESSION['success_mensaje'] .= ". Hubo $errores errores: " . implode('; ', array_slice($mensajes_error, 0, 3));
                    }
                } else {
                    $_SESSION['error_mensaje'] = "❌ No se generaron certificados. Errores: " . implode('; ', $mensajes_error);
                }
                
            } elseif ($accion === 'eliminar_certificados') {
                $eliminados = 0;
                
                foreach ($participantes_seleccionados as $participante_id) {
                    $participante_id = intval($participante_id);
                    
                    try {
                        // Obtener certificado
                        $stmt = $db->prepare("SELECT * FROM certificados WHERE participante_id = ?");
                        $stmt->execute([$participante_id]);
                        $certificado = $stmt->fetch();
                        
                        if ($certificado) {
                            // Eliminar archivo físico
                            $ruta_archivo = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
                            if (file_exists($ruta_archivo)) {
                                unlink($ruta_archivo);
                            }
                            
                            // Eliminar de BD
                            $stmt = $db->prepare("DELETE FROM certificados WHERE participante_id = ?");
                            if ($stmt->execute([$participante_id])) {
                                $eliminados++;
                                error_log("DEBUG: Certificado eliminado para participante $participante_id");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("DEBUG: Error eliminando certificado: " . $e->getMessage());
                    }
                }
                
                if ($eliminados > 0) {
                    $_SESSION['success_mensaje'] = "✅ Se eliminaron $eliminados certificados";
                } else {
                    $_SESSION['error_mensaje'] = "❌ No se eliminaron certificados";
                }
            }
            
        } catch (Exception $e) {
            $_SESSION['error_mensaje'] = 'Error del sistema: ' . $e->getMessage();
            error_log("DEBUG: Error general: " . $e->getMessage());
        }
    }
    
    // Redirigir para limpiar POST
    header("Location: generar.php?evento_id=" . $evento_id_post);
    exit;
}

// FUNCIÓN PARA GENERAR CERTIFICADO MASIVO (MEJORADA)
function generarCertificadoMasivo($participante, $plantilla) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Leer plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        if (!file_exists($ruta_plantilla)) {
            throw new Exception("Archivo de plantilla no encontrado: " . $plantilla['archivo_plantilla']);
        }
        
        $contenido_svg = file_get_contents($ruta_plantilla);
        if ($contenido_svg === false) {
            throw new Exception("No se pudo leer la plantilla SVG");
        }
        
        // Datos para reemplazar en la plantilla
        $datos_certificado = [
            '{{nombres}}' => $participante['nombres'],
            '{{apellidos}}' => $participante['apellidos'],
            '{{numero_identificacion}}' => $participante['numero_identificacion'],
            '{{correo_electronico}}' => $participante['correo_electronico'],
            '{{rol}}' => $participante['rol'],
            '{{telefono}}' => $participante['telefono'] ?: '',
            '{{institucion}}' => $participante['institucion'] ?: '',
            '{{evento_nombre}}' => $participante['evento_nombre'],
            '{{fecha_inicio}}' => date('d/m/Y', strtotime($participante['fecha_inicio'])),
            '{{fecha_fin}}' => date('d/m/Y', strtotime($participante['fecha_fin'])),
            '{{entidad_organizadora}}' => $participante['entidad_organizadora'],
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => $participante['lugar'] ?: 'Virtual',
            '{{horas_duracion}}' => $participante['horas_duracion'],
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{nombre_completo}}' => $participante['nombres'] . ' ' . $participante['apellidos'],
            '{{url_verificacion}}' => BASE_URL . 'public/verificar.php?codigo=' . $codigo_verificacion
        ];
        
        // Reemplazar variables en la plantilla
        $contenido_final = str_replace(array_keys($datos_certificado), array_values($datos_certificado), $contenido_svg);
        
        // Generar nombre del archivo
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Crear directorio si no existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $contenido_final) === false) {
            throw new Exception("No se pudo escribir el archivo SVG");
        }
        
        // Hash de validación para seguridad
        $hash_validacion = hash('sha256', $participante['id'] . $codigo_verificacion . $participante['nombres']);
        
        // Insertar registro en la base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo) 
            VALUES (?, ?, ?, ?, ?, 'svg')
        ");
        
        if ($stmt->execute([$participante['id'], $participante['evento_id'], $codigo_verificacion, $nombre_archivo, $hash_validacion])) {
            // Registrar auditoría
            registrarAuditoria('GENERAR_CERTIFICADO', 'certificados', $db->lastInsertId(), null, [
                'participante_id' => $participante['id'],
                'codigo_verificacion' => $codigo_verificacion,
                'archivo' => $nombre_archivo
            ]);
            
            return ['success' => true, 'codigo' => $codigo_verificacion, 'archivo' => $nombre_archivo];
        } else {
            throw new Exception("Error al insertar en la base de datos: " . implode(', ', $stmt->errorInfo()));
        }
        
    } catch (Exception $e) {
        error_log("Error en generarCertificadoMasivo: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados - Sistema de Certificados</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .participantes-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .participante-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            background: #fff;
        }
        
        .participante-item:hover {
            background: #f8f9fa;
        }
        
        .participante-item.tiene-certificado {
            background: #e8f5e8;
        }
        
        .participante-info {
            flex: 1;
            margin-left: 10px;
        }
        
        .participante-nombre {
            font-weight: bold;
            color: #333;
        }
        
        .participante-detalles {
            font-size: 0.9em;
            color: #666;
            margin-top: 2px;
        }
        
        .certificado-estado {
            font-size: 0.8em;
            padding: 2px 8px;
            border-radius: 3px;
            margin-left: 10px;
        }
        
        .estado-generado {
            background: #d4edda;
            color: #155724;
        }
        
        .estado-pendiente {
            background: #fff3cd;
            color: #856404;
        }
        
        .acciones-masivas {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: none;
        }
        
        .acciones-masivas.active {
            display: block;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .progress-info {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .progress-bar {
            background: #e9ecef;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #28a745, #20c997);
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">
            <h1>🎓 Sistema de Certificados</h1>
        </div>
        <div class="nav-menu">
            <ul>
                <li><a href="../index.php">Dashboard</a></li>
                <li><a href="../eventos/listar.php">Eventos</a></li>
                <li><a href="../participantes/listar.php">Participantes</a></li>
                <li><a href="generar.php" class="active">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="actions-bar">
            <div class="actions-left">
                <h2>📜 Generar Certificados</h2>
            </div>
            <div class="actions-right">
                <a href="../plantillas/listar.php" class="btn btn-secondary">
                    🎨 Gestionar Plantillas
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h3>🎯 Seleccionar Evento</h3>
            </div>
            <div class="card-body">
                <form method="GET">
                    <div class="form-group">
                        <label for="evento_id">Evento</label>
                        <select id="evento_id" name="evento_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Seleccionar evento --</option>
                            <?php foreach ($eventos as $evento): ?>
                                <option value="<?php echo $evento['id']; ?>" 
                                        <?php echo $evento_id == $evento['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($evento['nombre']); ?> 
                                    (<?php echo date('d/m/Y', strtotime($evento['fecha_inicio'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($evento_seleccionado && !empty($participantes)): ?>
            <?php
            // Calcular estadísticas
            $total_participantes = count($participantes);
            $con_certificado = 0;
            $sin_certificado = 0;
            
            foreach ($participantes as $p) {
                if ($p['certificado_id']) {
                    $con_certificado++;
                } else {
                    $sin_certificado++;
                }
            }
            
            $porcentaje = $total_participantes > 0 ? round(($con_certificado / $total_participantes) * 100, 1) : 0;
            ?>
            
            <div class="progress-info">
                <h4>📊 Progreso del Evento: <?php echo htmlspecialchars($evento_seleccionado['nombre']); ?></h4>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_participantes; ?></div>
                        <div class="stat-label">Total Participantes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $con_certificado; ?></div>
                        <div class="stat-label">Con Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $sin_certificado; ?></div>
                        <div class="stat-label">Pendientes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $porcentaje; ?>%</div>
                        <div class="stat-label">Completado</div>
                    </div>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $porcentaje; ?>%"></div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3>👥 Participantes del Evento</h3>
                    <div style="float: right;">
                        <button type="button" onclick="seleccionarTodos()" class="btn btn-secondary btn-sm">
                            ☑️ Seleccionar Todos
                        </button>
                        <button type="button" onclick="seleccionarSinCertificado()" class="btn btn-primary btn-sm">
                            📋 Solo Pendientes
                        </button>
                        <button type="button" onclick="limpiarSeleccion()" class="btn btn-secondary btn-sm">
                            🔄 Limpiar
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" id="form-acciones">
                        <input type="hidden" name="evento_id" value="<?php echo $evento_id; ?>">
                        
                        <div class="participantes-container">
                            <?php foreach ($participantes as $participante): ?>
                                <div class="participante-item <?php echo $participante['certificado_id'] ? 'tiene-certificado' : ''; ?>">
                                    <input type="checkbox" 
                                           name="participantes[]" 
                                           value="<?php echo $participante['id']; ?>"
                                           class="participante-checkbox"
                                           <?php echo !$participante['certificado_id'] ? '' : ''; ?>
                                           onchange="actualizarAcciones()">
                                    
                                    <div class="participante-info">
                                        <div class="participante-nombre">
                                            <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>
                                        </div>
                                        <div class="participante-detalles">
                                            🆔 <?php echo htmlspecialchars($participante['numero_identificacion']); ?> | 
                                            🎭 <?php echo htmlspecialchars($participante['rol']); ?> | 
                                            📧 <?php echo htmlspecialchars($participante['correo_electronico']); ?>
                                            <?php if ($participante['plantillas_disponibles'] == 0): ?>
                                                <span style="color: #dc3545;">⚠️ Sin plantilla</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="certificado-estado">
                                        <?php if ($participante['certificado_id']): ?>
                                            <span class="estado-generado">
                                                ✅ Generado
                                                <?php if ($participante['fecha_generacion']): ?>
                                                    <br><small><?php echo date('d/m/Y H:i', strtotime($participante['fecha_generacion'])); ?></small>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="estado-pendiente">⏳ Pendiente</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="acciones-masivas" id="acciones-masivas">
                            <h4>🛠️ Acciones Masivas</h4>
                            <p id="contador-seleccionados">0 participantes seleccionados</p>
                            
                            <div class="btn-group">
                                <button type="submit" name="accion" value="generar_certificados" 
                                        class="btn btn-success"
                                        onclick="return confirm('¿Está seguro de generar los certificados para los participantes seleccionados?')">
                                    ✨ Generar Certificados
                                </button>
                                
                                <button type="submit" name="accion" value="eliminar_certificados" 
                                        class="btn btn-danger"
                                        onclick="return confirm('⚠️ ¿Está seguro de eliminar los certificados seleccionados? Esta acción no se puede deshacer.')">
                                    🗑️ Eliminar Certificados
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($evento_seleccionado && empty($participantes)): ?>
            <div class="alert alert-info">
                ℹ️ Este evento no tiene participantes registrados. 
                <a href="../participantes/cargar.php?evento_id=<?php echo $evento_id; ?>">Cargar participantes</a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function seleccionarTodos() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = true);
            actualizarAcciones();
        }
        
        function seleccionarSinCertificado() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => {
                const item = checkbox.closest('.participante-item');
                checkbox.checked = !item.classList.contains('tiene-certificado');
            });
            actualizarAcciones();
        }
        
        function limpiarSeleccion() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = false);
            actualizarAcciones();
        }
        
        function actualizarAcciones() {
            const checkboxes = document.querySelectorAll('.participante-checkbox:checked');
            const contador = checkboxes.length;
            const accionesDiv = document.getElementById('acciones-masivas');
            const contadorSpan = document.getElementById('contador-seleccionados');
            
            if (contador > 0) {
                accionesDiv.classList.add('active');
                contadorSpan.textContent = `${contador} participante${contador > 1 ? 's' : ''} seleccionado${contador > 1 ? 's' : ''}`;
            } else {
                accionesDiv.classList.remove('active');
            }
        }
        
        // Actualizar acciones al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            actualizarAcciones();
        });
    </script>
</body>
</html>