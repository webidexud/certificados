<?php
// ===============================================
// ARCHIVO: admin/eventos/crear.php - SIMPLIFICADO
// ===============================================
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$error = '';
$success = '';

if ($_POST) {
    $nombre = limpiarDatos($_POST['nombre']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];
    $modalidad = $_POST['modalidad'];
    $entidad_organizadora = limpiarDatos($_POST['entidad_organizadora']);
    $lugar = limpiarDatos($_POST['lugar']);
    $horas_duracion = intval($_POST['horas_duracion']);
    
    // NUEVO: Manejar plantilla SVG
    $plantilla_svg = $_FILES['plantilla_svg'] ?? null;
    
    if (empty($nombre) || empty($fecha_inicio) || empty($fecha_fin) || empty($entidad_organizadora)) {
        $error = 'Complete los campos obligatorios';
    } elseif ($fecha_inicio > $fecha_fin) {
        $error = 'La fecha de fin debe ser posterior a la fecha de inicio';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // 1. Crear el evento
            $stmt = $db->prepare("
                INSERT INTO eventos (nombre, fecha_inicio, fecha_fin, modalidad, entidad_organizadora, lugar, horas_duracion) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $fecha_inicio, $fecha_fin, $modalidad, $entidad_organizadora, $lugar, $horas_duracion]);
            $evento_id = $db->lastInsertId();
            
            // 2. Si hay plantilla SVG, procesarla
            if ($plantilla_svg && $plantilla_svg['error'] === UPLOAD_ERR_OK) {
                $resultado_plantilla = procesarPlantillaSVG($plantilla_svg, $evento_id);
                if (!$resultado_plantilla['success']) {
                    $error = 'Evento creado pero error con plantilla: ' . $resultado_plantilla['error'];
                }
            }
            
            if (empty($error)) {
                registrarAuditoria('CREATE', 'eventos', $evento_id);
                mostrarMensaje('success', 'Evento creado exitosamente' . ($plantilla_svg ? ' con plantilla SVG' : ''));
                header('Location: listar.php');
                exit;
            }
            
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

function procesarPlantillaSVG($archivo, $evento_id) {
    try {
        // Validar que es SVG
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if ($extension !== 'svg') {
            return ['success' => false, 'error' => 'Solo se permiten archivos SVG'];
        }
        
        // Leer contenido
        $contenido = file_get_contents($archivo['tmp_name']);
        if (strpos($contenido, '<svg') === false) {
            return ['success' => false, 'error' => 'Archivo SVG no válido'];
        }
        
        // Validar variables mínimas requeridas
        $variables_requeridas = ['{{nombres}}', '{{apellidos}}', '{{evento_nombre}}', '{{codigo_verificacion}}'];
        foreach ($variables_requeridas as $variable) {
            if (strpos($contenido, $variable) === false) {
                return ['success' => false, 'error' => "Falta la variable: $variable"];
            }
        }
        
        // Guardar archivo
        $nombre_archivo = 'plantilla_evento_' . $evento_id . '_' . time() . '.svg';
        $ruta_destino = TEMPLATE_PATH . $nombre_archivo;
        
        if (!is_dir(TEMPLATE_PATH)) {
            mkdir(TEMPLATE_PATH, 0755, true);
        }
        
        if (file_put_contents($ruta_destino, $contenido)) {
            // Guardar referencia en base de datos
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO plantillas_certificados (evento_id, archivo_plantilla) 
                VALUES (?, ?)
            ");
            $stmt->execute([$evento_id, $nombre_archivo]);
            
            return ['success' => true, 'archivo' => $nombre_archivo];
        }
        
        return ['success' => false, 'error' => 'No se pudo guardar el archivo'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Evento - Sistema de Certificados</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .container { max-width: 800px; margin: 2rem auto; padding: 0 20px; }
        .card { background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; }
        .required { color: #dc3545; }
        input, select, textarea { width: 100%; padding: 0.75rem; border: 2px solid #e1e1e1; border-radius: 5px; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; }
        .btn-primary { background: #667eea; color: white; padding: 1rem 2rem; border: none; border-radius: 5px; cursor: pointer; }
        .btn-primary:hover { background: #5a67d8; }
        .alert { padding: 1rem; margin-bottom: 1rem; border-radius: 5px; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .file-upload { border: 3px dashed #667eea; padding: 2rem; text-align: center; border-radius: 10px; }
        .file-upload:hover { background: #f8f9ff; }
        .help-text { font-size: 0.9rem; color: #666; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="margin-bottom: 2rem; color: #333;">Crear Nuevo Evento</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="nombre">Nombre del Evento <span class="required">*</span></label>
                    <input type="text" id="nombre" name="nombre" required>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="fecha_inicio">Fecha Inicio <span class="required">*</span></label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" required>
                    </div>
                    <div class="form-group">
                        <label for="fecha_fin">Fecha Fin <span class="required">*</span></label>
                        <input type="date" id="fecha_fin" name="fecha_fin" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="entidad_organizadora">Entidad Organizadora <span class="required">*</span></label>
                    <input type="text" id="entidad_organizadora" name="entidad_organizadora" required>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="modalidad">Modalidad</label>
                        <select id="modalidad" name="modalidad">
                            <option value="presencial">Presencial</option>
                            <option value="virtual">Virtual</option>
                            <option value="hibrida">Híbrida</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="horas_duracion">Horas de Duración</label>
                        <input type="number" id="horas_duracion" name="horas_duracion" min="1">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="lugar">Lugar</label>
                    <input type="text" id="lugar" name="lugar" placeholder="Dirección o plataforma virtual">
                </div>
                
                <!-- NUEVA SECCIÓN: PLANTILLA SVG -->
                <div class="form-group">
                    <label for="plantilla_svg">Plantilla de Certificado (SVG)</label>
                    <div class="file-upload">
                        <input type="file" id="plantilla_svg" name="plantilla_svg" accept=".svg" style="display: none;">
                        <div onclick="document.getElementById('plantilla_svg').click();" style="cursor: pointer;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">🎨</div>
                            <div style="font-weight: 600;">Subir Plantilla SVG</div>
                            <div style="font-size: 0.9rem; color: #666; margin-top: 0.5rem;">
                                Haga clic para seleccionar un archivo SVG
                            </div>
                        </div>
                    </div>
                    <div class="help-text">
                        <strong>Variables requeridas en el SVG:</strong> {{nombres}}, {{apellidos}}, {{evento_nombre}}, {{codigo_verificacion}}<br>
                        <strong>Variables opcionales:</strong> {{fecha_inicio}}, {{fecha_fin}}, {{entidad_organizadora}}, {{modalidad}}, {{lugar}}, {{horas_duracion}}
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">Crear Evento</button>
                <a href="listar.php" style="margin-left: 1rem; color: #666; text-decoration: none;">Cancelar</a>
            </form>
        </div>
    </div>
    
    <script>
        // Mostrar nombre del archivo seleccionado
        document.getElementById('plantilla_svg').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const uploadDiv = document.querySelector('.file-upload');
            
            if (file) {
                uploadDiv.innerHTML = `
                    <div style="color: #28a745;">
                        <div style="font-size: 2rem;">✅</div>
                        <div style="font-weight: 600;">${file.name}</div>
                        <div style="font-size: 0.9rem;">Archivo SVG seleccionado</div>
                    </div>
                `;
            }
        });
        
        // Validar fechas
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            document.getElementById('fecha_fin').min = this.value;
        });
    </script>
</body>
</html>

<?php
// ===============================================
// ARCHIVO: admin/certificados/generar.php - SIMPLIFICADO
// ===============================================

function generarCertificadoIndividual($participante_id) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Obtener datos del participante
        $stmt = $db->prepare("
            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion
            FROM participantes p
            JOIN eventos e ON p.evento_id = e.id
            WHERE p.id = ?
        ");
        $stmt->execute([$participante_id]);
        $participante = $stmt->fetch();
        
        if (!$participante) {
            return ['success' => false, 'error' => 'Participante no encontrado'];
        }
        
        // Verificar si ya tiene certificado
        $stmt = $db->prepare("SELECT codigo_verificacion FROM certificados WHERE participante_id = ?");
        $stmt->execute([$participante_id]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Ya tiene certificado generado'];
        }
        
        // Generar código único
        $codigo_verificacion = generarCodigoUnico();
        
        // Buscar plantilla SVG del evento
        $stmt = $db->prepare("SELECT archivo_plantilla FROM plantillas_certificados WHERE evento_id = ? LIMIT 1");
        $stmt->execute([$participante['evento_id']]);
        $plantilla = $stmt->fetch();
        
        if ($plantilla) {
            // Generar con plantilla SVG
            $resultado = generarConPlantillaSVG($participante, $codigo_verificacion, $plantilla['archivo_plantilla']);
        } else {
            // Generar PDF básico
            $resultado = generarPDFBasico($participante, $codigo_verificacion);
        }
        
        if ($resultado['success']) {
            // Guardar en base de datos
            $stmt = $db->prepare("
                INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $hash = hash('sha256', $codigo_verificacion . $participante['numero_identificacion']);
            $stmt->execute([
                $participante_id, 
                $participante['evento_id'], 
                $codigo_verificacion, 
                $resultado['archivo'], 
                $hash
            ]);
            
            return ['success' => true, 'codigo' => $codigo_verificacion, 'archivo' => $resultado['archivo']];
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function generarConPlantillaSVG($participante, $codigo, $archivo_plantilla) {
    try {
        // Leer plantilla
        $ruta_plantilla = TEMPLATE_PATH . $archivo_plantilla;
        $contenido = file_get_contents($ruta_plantilla);
        
        // Reemplazar variables
        $variables = [
            '{{nombres}}' => $participante['nombres'],
            '{{apellidos}}' => $participante['apellidos'],
            '{{evento_nombre}}' => $participante['evento_nombre'],
            '{{codigo_verificacion}}' => $codigo,
            '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
            '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
            '{{entidad_organizadora}}' => $participante['entidad_organizadora'],
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => $participante['lugar'] ?: 'Virtual',
            '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
            '{{fecha_generacion}}' => date('d/m/Y')
        ];
        
        foreach ($variables as $variable => $valor) {
            $contenido = str_replace($variable, htmlspecialchars($valor, ENT_XML1), $contenido);
        }
        
        // Guardar archivo procesado
        $nombre_archivo = $codigo . '_' . time() . '.svg';
        $ruta_final = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        if (file_put_contents($ruta_final, $contenido)) {
            return ['success' => true, 'archivo' => $nombre_archivo];
        }
        
        return ['success' => false, 'error' => 'No se pudo guardar el certificado'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function generarPDFBasico($participante, $codigo) {
    // Generar PDF simple como fallback
    $contenido = "CERTIFICADO DE PARTICIPACION\n\n";
    $contenido .= "Se certifica que " . $participante['nombres'] . " " . $participante['apellidos'] . "\n";
    $contenido .= "participo en " . $participante['evento_nombre'] . "\n";
    $contenido .= "Codigo: " . $codigo;
    
    $nombre_archivo = $codigo . '_' . time() . '.txt';
    $ruta_final = GENERATED_PATH . 'certificados/' . $nombre_archivo;
    
    if (file_put_contents($ruta_final, $contenido)) {
        return ['success' => true, 'archivo' => $nombre_archivo];
    }
    
    return ['success' => false, 'error' => 'No se pudo generar certificado'];
}
?>