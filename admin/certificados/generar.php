<?php
// admin/certificados/generar.php - VERSIÓN SIMPLIFICADA QUE FUNCIONA
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
            // Obtener participantes con información de certificados
            $stmt = $db->prepare("
                SELECT p.*, 
                       c.id as certificado_id,
                       c.codigo_verificacion,
                       c.fecha_generacion
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
    }
}

// PROCESAR ACCIONES MASIVAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $participantes_seleccionados = $_POST['participantes'] ?? [];
    $evento_id_post = intval($_POST['evento_id'] ?? 0);
    
    error_log("DEBUG: Acción recibida: " . $accion);
    error_log("DEBUG: Participantes seleccionados: " . print_r($participantes_seleccionados, true));
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
                        
                        // Obtener datos del participante
                        $stmt = $db->prepare("
                            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion, e.descripcion
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
                        
                        // Buscar plantilla para el rol
                        $stmt = $db->prepare("
                            SELECT * FROM plantillas_certificados 
                            WHERE evento_id = ? AND (rol = ? OR rol = 'General') 
                            ORDER BY CASE WHEN rol = ? THEN 1 ELSE 2 END 
                            LIMIT 1
                        ");
                        $stmt->execute([$evento_id_post, $participante['rol'], $participante['rol']]);
                        $plantilla = $stmt->fetch();
                        
                        if (!$plantilla) {
                            $errores++;
                            $mensajes_error[] = $participante['nombres'] . ': No hay plantilla para rol ' . $participante['rol'];
                            continue;
                        }
                        
                        // Verificar archivo de plantilla
                        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
                        if (!file_exists($ruta_plantilla)) {
                            $errores++;
                            $mensajes_error[] = $participante['nombres'] . ': Archivo de plantilla no encontrado';
                            continue;
                        }
                        
                        // GENERAR CERTIFICADO
                        $resultado = generarCertificadoMasivo($participante, $plantilla);
                        
                        if ($resultado['success']) {
                            $generados++;
                            error_log("DEBUG: Certificado generado para " . $participante['nombres']);
                        } else {
                            $errores++;
                            $mensajes_error[] = $participante['nombres'] . ': ' . $resultado['error'];
                            error_log("DEBUG: Error generando certificado: " . $resultado['error']);
                        }
                        
                    } catch (Exception $e) {
                        $errores++;
                        $mensajes_error[] = "Error con participante ID $participante_id: " . $e->getMessage();
                        error_log("DEBUG: Excepción: " . $e->getMessage());
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

// FUNCIÓN PARA GENERAR CERTIFICADO (COPIADA DEL INDIVIDUAL)
function generarCertificadoMasivo($participante, $plantilla) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Leer plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        $contenido_svg = file_get_contents($ruta_plantilla);
        
        if ($contenido_svg === false) {
            throw new Exception("No se pudo leer la plantilla SVG");
        }
        
        // Datos para reemplazar
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
            '{{url_verificacion}}' => BASE_URL . 'public/verificar.php?codigo=' . $codigo_verificacion
        ];
        
        // Reemplazar variables
        $contenido_final = str_replace(array_keys($datos_certificado), array_values($datos_certificado), $contenido_svg);
        
        // Generar archivo
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
        
        // Hash de validación
        $hash_validacion = hash('sha256', $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d'));
        
        // Insertar en BD
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, 'svg', NOW())
        ");
        
        $resultado_bd = $stmt->execute([
            $participante['id'],
            $participante['evento_id'],
            $codigo_verificacion,
            $nombre_archivo,
            $hash_validacion
        ]);
        
        if (!$resultado_bd) {
            throw new Exception("Error al insertar en base de datos");
        }
        
        return [
            'success' => true,
            'codigo_verificacion' => $codigo_verificacion,
            'archivo' => $nombre_archivo
        ];
        
    } catch (Exception $e) {
        error_log("Error en generarCertificadoMasivo: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados Masivos</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .alert { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: 20px 0; }
        .card-header { background: #f8f9fa; padding: 15px; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { padding: 20px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 500; margin: 5px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; }
        .table th { background: #f8f9fa; font-weight: 600; }
        .table tbody tr:hover { background: #f8f9fa; }
        .status-generated { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .status-pending { background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .actions-bar { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; display: flex; gap: 10px; align-items: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎓 Generación Masiva de Certificados</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Selector de Evento -->
        <div class="card">
            <div class="card-header">📅 Seleccionar Evento</div>
            <div class="card-body">
                <form method="GET" action="">
                    <select name="evento_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Seleccione un evento...</option>
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
        </div>
        
        <?php if ($evento_seleccionado && !empty($participantes)): ?>
            
            <!-- Formulario de Acciones Masivas -->
            <form method="POST" action="" id="formAccionMasiva">
                <input type="hidden" name="evento_id" value="<?php echo $evento_id; ?>">
                
                <!-- Barra de Acciones -->
                <div class="actions-bar">
                    <span id="selectionCounter">0 participantes seleccionados</span>
                    <button type="submit" name="accion" value="generar_certificados" class="btn btn-success" onclick="return confirmarAccion('generar')">
                        🎓 Generar Certificados
                    </button>
                    <button type="submit" name="accion" value="eliminar_certificados" class="btn btn-danger" onclick="return confirmarAccion('eliminar')">
                        🗑️ Eliminar Certificados
                    </button>
                </div>
                
                <!-- Tabla de Participantes -->
                <div class="card">
                    <div class="card-header">
                        👥 Participantes del Evento: <?php echo htmlspecialchars($evento_seleccionado['nombre']); ?>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                                    </th>
                                    <th>Participante</th>
                                    <th>Identificación</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Código</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participantes as $participante): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" 
                                                   name="participantes[]" 
                                                   value="<?php echo $participante['id']; ?>"
                                                   class="participante-checkbox"
                                                   onchange="actualizarContador()">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($participante['correo_electronico']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($participante['numero_identificacion']); ?></td>
                                        <td><?php echo htmlspecialchars($participante['rol']); ?></td>
                                        <td>
                                            <?php if ($participante['certificado_id']): ?>
                                                <span class="status-generated">✅ Generado</span>
                                            <?php else: ?>
                                                <span class="status-pending">⏳ Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($participante['codigo_verificacion']): ?>
                                                <code><?php echo htmlspecialchars($participante['codigo_verificacion']); ?></code>
                                            <?php else: ?>
                                                <em>Sin generar</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
            
        <?php elseif ($evento_seleccionado && empty($participantes)): ?>
            <div class="alert alert-error">
                ❌ El evento seleccionado no tiene participantes registrados.
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        let participantesSeleccionados = 0;
        
        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            actualizarContador();
        }
        
        function actualizarContador() {
            const checkboxes = document.querySelectorAll('.participante-checkbox:checked');
            participantesSeleccionados = checkboxes.length;
            
            const contador = document.getElementById('selectionCounter');
            contador.textContent = `${participantesSeleccionados} participante${participantesSeleccionados !== 1 ? 's' : ''} seleccionado${participantesSeleccionados !== 1 ? 's' : ''}`;
        }
        
        function confirmarAccion(accion) {
            if (participantesSeleccionados === 0) {
                alert('❌ Debe seleccionar al menos un participante.');
                return false;
            }
            
            let mensaje = '';
            if (accion === 'generar') {
                mensaje = `🎓 ¿Generar certificados para ${participantesSeleccionados} participante${participantesSeleccionados !== 1 ? 's' : ''}?`;
            } else if (accion === 'eliminar') {
                mensaje = `🗑️ ¿Eliminar certificados de ${participantesSeleccionados} participante${participantesSeleccionados !== 1 ? 's' : ''}?\n\n⚠️ Esta acción no se puede deshacer.`;
            }
            
            return confirm(mensaje);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            actualizarContador();
            
            // Auto-ocultar mensajes
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                });
            }, 5000);
        });
    </script>
</body>
</html>