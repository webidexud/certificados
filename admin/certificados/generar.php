<?php
// admin/certificados/generar.php - VERSIÓN COMPLETA MEJORADA
require_once '../../config/config.php';
require_once '../../includes/funciones.php';
require_once '../../includes/funciones_svg.php';

verificarAutenticacion();

$error = '';
$success = '';
$participante_individual = null;
$estadisticas_evento = null;

// Si viene un participante específico por URL
if (isset($_GET['participante_id'])) {
    $participante_id = intval($_GET['participante_id']);
    
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
                   e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion,
                   (SELECT COUNT(*) FROM certificados c WHERE c.participante_id = p.id) as tiene_certificado
            FROM participantes p 
            JOIN eventos e ON p.evento_id = e.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$participante_id]);
        $participante_individual = $stmt->fetch();
        
        if (!$participante_individual) {
            $error = "Participante no encontrado";
        } elseif ($participante_individual['tiene_certificado'] > 0) {
            // Obtener código del certificado existente
            $stmt = $db->prepare("SELECT codigo_verificacion FROM certificados WHERE participante_id = ?");
            $stmt->execute([$participante_id]);
            $certificado_existente = $stmt->fetch();
            $error = "Este participante ya tiene un certificado generado con código: " . $certificado_existente['codigo_verificacion'];
        }
    } catch (Exception $e) {
        $error = "Error al cargar el participante: " . $e->getMessage();
    }
}

// Obtener lista de eventos para el filtro
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("
        SELECT e.id, e.nombre, e.fecha_inicio, e.fecha_fin,
               COUNT(DISTINCT p.id) as total_participantes,
               COUNT(DISTINCT c.id) as total_certificados,
               COUNT(DISTINCT pt.id) as tiene_plantillas
        FROM eventos e 
        LEFT JOIN participantes p ON e.id = p.evento_id
        LEFT JOIN certificados c ON p.id = c.participante_id
        LEFT JOIN plantillas_certificados pt ON e.id = pt.evento_id
        GROUP BY e.id, e.nombre, e.fecha_inicio, e.fecha_fin
        ORDER BY e.fecha_inicio DESC
    ");
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

// Obtener estadísticas si hay un evento seleccionado
if (isset($_GET['evento_id']) && !$_POST) {
    $evento_id_stats = intval($_GET['evento_id']);
    $estadisticas_evento = obtenerEstadisticasEvento($evento_id_stats);
}

if ($_POST) {
    $accion = $_POST['accion'];
    $evento_id = intval($_POST['evento_id']);
    
    if (empty($evento_id)) {
        $error = 'Debe seleccionar un evento';
    } else {
        try {
            set_time_limit(300); // 5 minutos para generación masiva
            
            if ($accion === 'generar_individual' && isset($_POST['participante_id'])) {
                // Generar certificado individual
                $participante_id = intval($_POST['participante_id']);
                $resultado = generarCertificadoIndividual($participante_id);
                
                if ($resultado['success']) {
                    $tipo_archivo = $resultado['tipo'] ?? 'pdf';
                    $icono_tipo = $tipo_archivo === 'svg' ? '🎨' : '📄';
                    
                    $success = "✅ <strong>Certificado {$tipo_archivo} generado exitosamente</strong><br>" .
                              "👤 <strong>Participante:</strong> " . $resultado['participante'] . "<br>" .
                              "🔑 <strong>Código:</strong> " . $resultado['codigo'] . "<br>" .
                              "{$icono_tipo} <strong>Archivo:</strong> " . $resultado['archivo'];
                              
                    if (isset($resultado['dimensiones'])) {
                        $success .= "<br>📏 <strong>Dimensiones:</strong> {$resultado['dimensiones']['ancho']}x{$resultado['dimensiones']['alto']}px";
                    }
                    
                    if (isset($resultado['url_descarga'])) {
                        $success .= "<br><a href='{$resultado['url_descarga']}' target='_blank' class='btn-download'>📥 Descargar Certificado</a>";
                    }
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
                              
                    if (isset($resultado['tipos_generados'])) {
                        $success .= "<br>🎨 <strong>SVG:</strong> {$resultado['tipos_generados']['svg']} | 📄 <strong>PDF:</strong> {$resultado['tipos_generados']['pdf']}";
                    }
                              
                    if (!empty($resultado['detalles_errores']) && count($resultado['detalles_errores']) <= 5) {
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

// FUNCIONES MEJORADAS PARA GENERACIÓN DE CERTIFICADOS

function generarCertificadoIndividual($participante_id) {
    $tiempo_inicio = microtime(true);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Obtener datos del participante y evento
        $stmt = $db->prepare("
            SELECT 
                p.*,
                e.id as evento_id,
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
        
        // Verificar plantillas disponibles
        $plantilla_info = obtenerPlantillaDisponible($participante['evento_id'], $participante['rol']);
        
        if ($plantilla_info['tiene_plantilla']) {
            // Generar certificado SVG con plantilla
            $resultado_certificado = generarCertificadoConPlantillaSVG($participante, $codigo_verificacion, $plantilla_info['plantilla']);
        } else {
            // Generar certificado PDF básico
            $resultado_certificado = generarPDFCertificadoBasico($participante, $codigo_verificacion);
        }
        
        if (!$resultado_certificado['success']) {
            return ['success' => false, 'error' => 'Error al generar certificado: ' . $resultado_certificado['error']];
        }
        
        // Insertar registro en la base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, dimensiones, estado, fecha_generacion)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'generado', NOW())
        ");
        
        $tipo_archivo = $resultado_certificado['tipo'] ?? 'pdf';
        $dimensiones_json = isset($resultado_certificado['dimensiones']) ? json_encode($resultado_certificado['dimensiones']) : null;
        
        $stmt->execute([
            $participante_id,
            $participante['evento_id'],
            $codigo_verificacion,
            $resultado_certificado['nombre_archivo'],
            $hash_validacion,
            $tipo_archivo,
            $dimensiones_json
        ]);
        
        $certificado_id = $db->lastInsertId();
        
        // Registrar en auditoría
        registrarAuditoria('GENERAR_CERTIFICADO', 'certificados', $certificado_id, null, [
            'participante_id' => $participante_id,
            'codigo_verificacion' => $codigo_verificacion,
            'tipo' => $tipo_archivo,
            'tiempo_generacion' => round(microtime(true) - $tiempo_inicio, 3),
            'plantilla_utilizada' => $plantilla_info['plantilla']['nombre_plantilla'] ?? 'PDF Básico'
        ]);
        
        return [
            'success' => true,
            'participante' => $participante['nombres'] . ' ' . $participante['apellidos'],
            'codigo' => $codigo_verificacion,
            'archivo' => $resultado_certificado['nombre_archivo'],
            'tipo' => $tipo_archivo,
            'certificado_id' => $certificado_id,
            'dimensiones' => $resultado_certificado['dimensiones'] ?? null,
            'url_descarga' => GENERATED_URL . 'certificados/' . $resultado_certificado['nombre_archivo']
        ];
        
    } catch (Exception $e) {
        error_log("Error generando certificado individual: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

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
            ORDER BY p.rol, p.apellidos, p.nombres
        ");
        $stmt->execute([$evento_id]);
        $participantes = $stmt->fetchAll();
        
        if (empty($participantes)) {
            return ['success' => false, 'error' => 'No hay participantes sin certificado en este evento'];
        }
        
        $generados = 0;
        $errores = 0;
        $detalles_errores = [];
        $tipos_generados = ['svg' => 0, 'pdf' => 0];
        
        // Agrupar por rol para optimizar plantillas
        $participantes_por_rol = [];
        foreach ($participantes as $participante) {
            $participantes_por_rol[$participante['rol']][] = $participante;
        }
        
        // Pre-cargar plantillas disponibles
        $plantillas_disponibles = [];
        foreach (array_keys($participantes_por_rol) as $rol) {
            $plantillas_disponibles[$rol] = obtenerPlantillaDisponible($evento_id, $rol);
        }
        
        // Procesar participantes agrupados por rol
        foreach ($participantes_por_rol as $rol => $participantes_rol) {
            $plantilla_info = $plantillas_disponibles[$rol];
            
            foreach ($participantes_rol as $participante) {
                try {
                    $codigo_verificacion = generarCodigoUnico();
                    $hash_validacion = generarHashValidacion($participante, $codigo_verificacion);
                    
                    if ($plantilla_info['tiene_plantilla']) {
                        $resultado_certificado = generarCertificadoConPlantillaSVG($participante, $codigo_verificacion, $plantilla_info['plantilla']);
                    } else {
                        $resultado_certificado = generarPDFCertificadoBasico($participante, $codigo_verificacion);
                    }
                    
                    if ($resultado_certificado['success']) {
                        // Insertar en base de datos
                        $stmt = $db->prepare("
                            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, dimensiones, estado, fecha_generacion)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'generado', NOW())
                        ");
                        
                        $tipo_archivo = $resultado_certificado['tipo'] ?? 'pdf';
                        $dimensiones_json = isset($resultado_certificado['dimensiones']) ? json_encode($resultado_certificado['dimensiones']) : null;
                        
                        $stmt->execute([
                            $participante['id'],
                            $evento_id,
                            $codigo_verificacion,
                            $resultado_certificado['nombre_archivo'],
                            $hash_validacion,
                            $tipo_archivo,
                            $dimensiones_json
                        ]);
                        
                        $generados++;
                        $tipos_generados[$tipo_archivo]++;
                        
                    } else {
                        $errores++;
                        $detalles_errores[] = $participante['nombres'] . ' ' . $participante['apellidos'] . ': ' . $resultado_certificado['error'];
                    }
                    
                } catch (Exception $e) {
                    $errores++;
                    $detalles_errores[] = $participante['nombres'] . ' ' . $participante['apellidos'] . ': ' . $e->getMessage();
                }
                
                // Pequeña pausa cada 10 certificados para no sobrecargar
                if (($generados + $errores) % 10 == 0) {
                    usleep(50000); // 0.05 segundos
                }
            }
        }
        
        $tiempo_total = round(microtime(true) - $tiempo_inicio, 2);
        
        // Registrar auditoría del proceso masivo
        registrarAuditoria('GENERAR_MASIVO', 'certificados', $evento_id, null, [
            'evento_id' => $evento_id,
            'generados' => $generados,
            'errores' => $errores,
            'tiempo_total' => $tiempo_total,
            'tipos_generados' => $tipos_generados
        ]);
        
        return [
            'success' => true,
            'generados' => $generados,
            'errores' => $errores,
            'tiempo' => $tiempo_total,
            'tipos_generados' => $tipos_generados,
            'detalles_errores' => array_slice($detalles_errores, 0, 10) // Máximo 10 errores mostrados
        ];
        
    } catch (Exception $e) {
        error_log("Error en generación masiva: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function obtenerPlantillaDisponible($evento_id, $rol) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Buscar plantilla específica para el rol
        $stmt = $db->prepare("
            SELECT * FROM plantillas_certificados 
            WHERE evento_id = ? AND rol = ? 
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$evento_id, $rol]);
        $plantilla = $stmt->fetch();
        
        if ($plantilla) {
            return ['tiene_plantilla' => true, 'plantilla' => $plantilla];
        }
        
        // Si no hay plantilla específica, buscar plantilla general
        $stmt = $db->prepare("
            SELECT * FROM plantillas_certificados 
            WHERE evento_id = ? AND rol = 'General' 
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$evento_id]);
        $plantilla = $stmt->fetch();
        
        if ($plantilla) {
            return ['tiene_plantilla' => true, 'plantilla' => $plantilla];
        }
        
        return ['tiene_plantilla' => false, 'plantilla' => null];
        
    } catch (Exception $e) {
        error_log("Error obteniendo plantilla: " . $e->getMessage());
        return ['tiene_plantilla' => false, 'plantilla' => null];
    }
}

function generarCertificadoConPlantillaSVGMejorado($participante, $codigo_verificacion, $plantilla) {
    try {
        // Leer plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        if (!file_exists($ruta_plantilla)) {
            throw new Exception("Archivo de plantilla SVG no encontrado: " . $plantilla['archivo_plantilla']);
        }
        
        $contenido_svg = file_get_contents($ruta_plantilla);
        if (empty($contenido_svg)) {
            throw new Exception("La plantilla SVG está vacía");
        }
        
        // Optimizar SVG para mejor renderizado
        $contenido_svg = optimizarSVGTexto($contenido_svg);
        
        // Preparar datos para reemplazar variables
        $datos_certificado = [
            '{{numero_identificacion}}' => htmlspecialchars($participante['numero_identificacion'], ENT_XML1, 'UTF-8'),
            '{{correo_electronico}}' => htmlspecialchars($participante['correo_electronico'], ENT_XML1, 'UTF-8'),
            '{{rol}}' => htmlspecialchars($participante['rol'], ENT_XML1, 'UTF-8'),
            '{{telefono}}' => htmlspecialchars($participante['telefono'] ?: '', ENT_XML1, 'UTF-8'),
            '{{institucion}}' => htmlspecialchars($participante['institucion'] ?: '', ENT_XML1, 'UTF-8'),
            '{{evento_descripcion}}' => htmlspecialchars($participante['descripcion'] ?: '', ENT_XML1, 'UTF-8'),
            '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
            '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
            '{{entidad_organizadora}}' => htmlspecialchars($participante['entidad_organizadora'], ENT_XML1, 'UTF-8'),
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => htmlspecialchars($participante['lugar'] ?: 'Virtual', ENT_XML1, 'UTF-8'),
            '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{fecha_emision}}' => date('d/m/Y'),
            '{{año}}' => date('Y'),
            '{{mes}}' => date('m'),
            '{{dia}}' => date('d'),
            '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion,
            '{{numero_certificado}}' => 'CERT-' . date('Y') . '-' . str_pad($participante['id'], 6, '0', STR_PAD_LEFT),
            '{{firma_digital}}' => 'Certificado Digital Verificado',
            '{{mes_nombre}}' => obtenerNombreMes(date('n')),
            '{{año_completo}}' => date('Y'),
            '{{duracion_texto}}' => $participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'Duración no especificada',
            '{{modalidad_completa}}' => obtenerModalidadCompleta($participante['modalidad']),
            '{{nombre_completo}}' => htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos'], ENT_XML1, 'UTF-8'),
            '{{iniciales}}' => obtenerIniciales($participante['nombres'], $participante['apellidos']),
        ];
        
        // PROCESAR TEXTOS LARGOS CON NUEVA LÓGICA
        
        // 1. Procesar nombres largos (manejo especial)
        $contenido_svg = procesarNombresLargos($contenido_svg, $participante['nombres'], $participante['apellidos']);
        
        // 2. Procesar evento largo (manejo especial)
        $contenido_svg = procesarEventosLargos($contenido_svg, $participante['evento_nombre']);
        
        // 3. Reemplazar el resto de variables normalmente
        foreach ($datos_certificado as $variable => $valor) {
            $contenido_svg = str_replace($variable, $valor, $contenido_svg);
        }
        
        // 4. Limpiar variables no reemplazadas
        $contenido_svg = preg_replace('/\{\{[^}]+\}\}/', '', $contenido_svg);
        
        // 5. Validar que el SVG resultante sea válido
        if (strpos($contenido_svg, '<svg') === false) {
            throw new Exception("El SVG procesado no es válido");
        }
        
        // Generar nombre de archivo único
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar SVG procesado
        if (file_put_contents($ruta_completa, $contenido_svg) === false) {
            throw new Exception("No se pudo escribir el archivo SVG");
        }
        
        return [
            'success' => true,
            'nombre_archivo' => $nombre_archivo,
            'ruta_completa' => $ruta_completa,
            'tamaño' => filesize($ruta_completa),
            'tipo' => 'svg',
            'dimensiones' => [
                'ancho' => $plantilla['ancho'],
                'alto' => $plantilla['alto']
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Error generando SVG mejorado: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function generarCertificadoConPlantillaSVG($participante, $codigo_verificacion, $plantilla) {
    try {
        // Incluir funciones SVG mejoradas
        if (!function_exists('procesarNombresLargos')) {
            require_once __DIR__ . '/../../includes/funciones_svg.php';
        }
        
        // Leer plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        if (!file_exists($ruta_plantilla)) {
            throw new Exception("Archivo de plantilla SVG no encontrado: " . $plantilla['archivo_plantilla']);
        }
        
        $contenido_svg = file_get_contents($ruta_plantilla);
        if (empty($contenido_svg)) {
            throw new Exception("La plantilla SVG está vacía");
        }
        
        // Optimizar SVG para mejor renderizado
        $contenido_svg = optimizarSVGTexto($contenido_svg);
        
        // PROCESAR NOMBRES LARGOS PRIMERO (MANEJO ESPECIAL)
        $contenido_svg = procesarNombresLargos($contenido_svg, $participante['nombres'], $participante['apellidos']);
        
        // PROCESAR EVENTO LARGO (MANEJO ESPECIAL)  
        $contenido_svg = procesarEventosLargos($contenido_svg, $participante['evento_nombre']);
        
        // Preparar datos para variables restantes
        $datos_certificado = [
            '{{numero_identificacion}}' => htmlspecialchars($participante['numero_identificacion'], ENT_XML1, 'UTF-8'),
            '{{correo_electronico}}' => htmlspecialchars($participante['correo_electronico'], ENT_XML1, 'UTF-8'),
            '{{rol}}' => htmlspecialchars($participante['rol'], ENT_XML1, 'UTF-8'),
            '{{telefono}}' => htmlspecialchars($participante['telefono'] ?: '', ENT_XML1, 'UTF-8'),
            '{{institucion}}' => htmlspecialchars($participante['institucion'] ?: '', ENT_XML1, 'UTF-8'),
            '{{evento_descripcion}}' => htmlspecialchars($participante['descripcion'] ?: '', ENT_XML1, 'UTF-8'),
            '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
            '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
            '{{entidad_organizadora}}' => htmlspecialchars($participante['entidad_organizadora'], ENT_XML1, 'UTF-8'),
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => htmlspecialchars($participante['lugar'] ?: 'Virtual', ENT_XML1, 'UTF-8'),
            '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{fecha_emision}}' => date('d/m/Y'),
            '{{año}}' => date('Y'),
            '{{mes}}' => date('m'),
            '{{dia}}' => date('d'),
            '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion,
            '{{numero_certificado}}' => 'CERT-' . date('Y') . '-' . str_pad($participante['id'], 6, '0', STR_PAD_LEFT),
            '{{firma_digital}}' => 'Certificado Digital Verificado',
            '{{mes_nombre}}' => obtenerNombreMes(date('n')),
            '{{año_completo}}' => date('Y'),
            '{{duracion_texto}}' => $participante['horas_duracion'] ? $participante['horas_duracion'] . ' horas académicas' : 'Duración no especificada',
            '{{modalidad_completa}}' => obtenerModalidadCompleta($participante['modalidad']),
            '{{nombre_completo}}' => htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos'], ENT_XML1, 'UTF-8'),
            '{{iniciales}}' => obtenerIniciales($participante['nombres'], $participante['apellidos']),
        ];
        
        // Reemplazar variables restantes
        foreach ($datos_certificado as $variable => $valor) {
            $contenido_svg = str_replace($variable, $valor, $contenido_svg);
        }
        
        // Limpiar cualquier variable no reemplazada
        $contenido_svg = preg_replace('/\{\{[^}]+\}\}/', '', $contenido_svg);
        
        // Generar nombre de archivo único
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.svg';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Guardar SVG procesado
        if (file_put_contents($ruta_completa, $contenido_svg) === false) {
            throw new Exception("No se pudo escribir el archivo SVG");
        }
        
        return [
            'success' => true,
            'nombre_archivo' => $nombre_archivo,
            'ruta_completa' => $ruta_completa,
            'tamaño' => filesize($ruta_completa),
            'tipo' => 'svg',
            'dimensiones' => [
                'ancho' => $plantilla['ancho'],
                'alto' => $plantilla['alto']
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Error generando SVG: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function generarPDFCertificadoBasico($participante, $codigo_verificacion) {
    try {
        // Generar nombre de archivo único
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Generar contenido del PDF básico mejorado
        $contenido_pdf = generarContenidoPDFMejorado($participante, $codigo_verificacion);
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $contenido_pdf) === false) {
            throw new Exception("No se pudo escribir el archivo PDF");
        }
        
        return [
            'success' => true,
            'nombre_archivo' => $nombre_archivo,
            'ruta_completa' => $ruta_completa,
            'tamaño' => filesize($ruta_completa),
            'tipo' => 'pdf'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function generarContenidoPDFMejorado($participante, $codigo_verificacion) {
    // Generar texto más completo para el PDF
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $evento_nombre = strtoupper($participante['evento_nombre']);
    $entidad = $participante['entidad_organizadora'];
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $rol = $participante['rol'];
    $modalidad = ucfirst($participante['modalidad']);
    
    $contenido_certificado = "CERTIFICADO DE PARTICIPACION\\n\\n";
    $contenido_certificado .= "Se certifica que\\n\\n";
    $contenido_certificado .= "{$nombre_completo}\\n\\n";
    $contenido_certificado .= "participo exitosamente en el evento\\n\\n";
    $contenido_certificado .= "{$evento_nombre}\\n\\n";
    $contenido_certificado .= "organizado por {$entidad}\\n";
    $contenido_certificado .= "del {$fecha_inicio} al {$fecha_fin}\\n";
    $contenido_certificado .= "Modalidad: {$modalidad}\\n";
    
    if ($participante['lugar']) {
        $contenido_certificado .= "Lugar: " . $participante['lugar'] . "\\n";
    }
    
    if ($participante['horas_duracion']) {
        $contenido_certificado .= "Duracion: " . $participante['horas_duracion'] . " horas academicas\\n";
    }
    
    $contenido_certificado .= "\\nRol: {$rol}\\n\\n";
    $contenido_certificado .= "Codigo de verificacion: {$codigo_verificacion}\\n";
    $contenido_certificado .= "Fecha de emision: " . date('d/m/Y H:i') . "\\n";
    $contenido_certificado .= "\\nEste certificado puede verificarse en:\\n";
    $contenido_certificado .= PUBLIC_URL . "verificar.php?codigo={$codigo_verificacion}";
    
    // Generar estructura PDF mejorada
    $pdf_content = "%PDF-1.4\n";
    $pdf_content .= "1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n\n";
    $pdf_content .= "2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n\n";
    $pdf_content .= "3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n/Contents 4 0 R\n";
    $pdf_content .= "/Resources <<\n/Font <<\n/F1 5 0 R\n>>\n>>\n>>\nendobj\n\n";
    
    $longitud_contenido = strlen($contenido_certificado) + 100;
    $pdf_content .= "4 0 obj\n<<\n/Length {$longitud_contenido}\n>>\nstream\nBT\n";
    $pdf_content .= "/F1 16 Tf\n50 700 Td\n";
    $pdf_content .= "({$contenido_certificado}) Tj\n";
    $pdf_content .= "ET\nendstream\nendobj\n\n";
    
    $pdf_content .= "5 0 obj\n<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica\n>>\nendobj\n\n";
    
    $pdf_content .= "xref\n0 6\n";
    $pdf_content .= "0000000000 65535 f \n";
    $pdf_content .= "0000000010 65535 n \n";
    $pdf_content .= "0000000053 65535 n \n";
    $pdf_content .= "0000000125 65535 n \n";
    $pdf_content .= "0000000348 65535 n \n";
    $pdf_content .= "0000000500 65535 n \n";
    $pdf_content .= "trailer\n<<\n/Size 6\n/Root 1 0 R\n>>\n";
    $pdf_content .= "startxref\n625\n%%EOF\n";
    
    return $pdf_content;
}

function obtenerEstadisticasEvento($evento_id) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Estadísticas básicas
        $stmt = $db->prepare("
            SELECT 
                e.nombre as evento_nombre,
                COUNT(DISTINCT p.id) as total_participantes,
                COUNT(DISTINCT c.id) as total_certificados,
                COUNT(DISTINCT pt.id) as total_plantillas,
                COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'svg' THEN c.id END) as certificados_svg,
                COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'pdf' THEN c.id END) as certificados_pdf
            FROM eventos e
            LEFT JOIN participantes p ON e.id = p.evento_id
            LEFT JOIN certificados c ON p.id = c.participante_id
            LEFT JOIN plantillas_certificados pt ON e.id = pt.evento_id
            WHERE e.id = ?
            GROUP BY e.id, e.nombre
        ");
        $stmt->execute([$evento_id]);
        $stats = $stmt->fetch();
        
        if (!$stats) {
            return null;
        }
        
        // Estadísticas por rol
        $stmt = $db->prepare("
            SELECT 
                p.rol,
                COUNT(*) as total,
                COUNT(c.id) as con_certificado,
                COUNT(pt.id) as tiene_plantilla
            FROM participantes p 
            LEFT JOIN certificados c ON p.id = c.participante_id
            LEFT JOIN plantillas_certificados pt ON p.evento_id = pt.evento_id AND p.rol = pt.rol
            WHERE p.evento_id = ? 
            GROUP BY p.rol 
            ORDER BY total DESC
        ");
        $stmt->execute([$evento_id]);
        $stats_por_rol = $stmt->fetchAll();
        
        $stats['por_rol'] = $stats_por_rol;
        $stats['pendientes'] = $stats['total_participantes'] - $stats['total_certificados'];
        $stats['porcentaje_completado'] = $stats['total_participantes'] > 0 ? 
            round(($stats['total_certificados'] / $stats['total_participantes']) * 100, 1) : 0;
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
        return null;
    }
}

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
        'salt' => 'certificados_digitales_svg_2025_' . date('Y-m-d'),
        'version' => '3.0'
    ];
    
    return hash('sha256', json_encode($datos_validacion, JSON_UNESCAPED_UNICODE));
}

// Funciones auxiliares para el SVG
function obtenerNombreMes($numero_mes) {
    $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
    ];
    return $meses[$numero_mes] ?? 'mes';
}

function obtenerModalidadCompleta($modalidad) {
    $modalidades = [
        'presencial' => 'Modalidad Presencial',
        'virtual' => 'Modalidad Virtual',
        'hibrida' => 'Modalidad Híbrida'
    ];
    return $modalidades[$modalidad] ?? ucfirst($modalidad);
}

function obtenerIniciales($nombres, $apellidos) {
    $iniciales = '';
    $palabras_nombres = explode(' ', trim($nombres));
    $palabras_apellidos = explode(' ', trim($apellidos));
    
    foreach ($palabras_nombres as $palabra) {
        if (!empty($palabra)) {
            $iniciales .= strtoupper(substr($palabra, 0, 1));
        }
    }
    
    foreach ($palabras_apellidos as $palabra) {
        if (!empty($palabra)) {
            $iniciales .= strtoupper(substr($palabra, 0, 1));
        }
    }
    
    return $iniciales;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados - Sistema de Certificados SVG</title>
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
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .page-title h2 {
            color: #333;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #666;
            font-size: 1.1rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .card-header {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            font-size: 1.3rem;
            color: #333;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-description {
            color: #666;
            font-size: 0.95rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f8f9ff 0%, #e8efff 100%);
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            border: 2px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(102, 126, 234, 0.3);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
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
        
        select, input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #28a745;
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-secondary:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 10px;
            border-left: 4px solid;
        }
        
        .alert-success {
            background-color: #d4f6d4;
            color: #0f5132;
            border-left-color: #28a745;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }
        
        .participante-info {
            background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
            border: 2px solid #28a745;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .participante-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: #155724;
            margin-bottom: 0.25rem;
            font-weight: 500;
        }
        
        .detail-value {
            font-weight: 600;
            color: #0f5132;
        }
        
        .progress-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 1rem 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
            transition: width 0.3s ease;
            border-radius: 10px;
        }
        
        .btn-download {
            background: #17a2b8;
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            margin-top: 1rem;
            display: inline-block;
        }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        
        .loading-content {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
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
            .header-content { flex-direction: column; gap: 1rem; }
            .nav ul { flex-wrap: wrap; gap: 1rem; }
            .btn-group { flex-direction: column; }
            .stats-grid { grid-template-columns: 1fr; }
            .participante-details { grid-template-columns: 1fr; }
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
                <h2>🏆 Generador de Certificados</h2>
            </div>
            <p class="page-subtitle">Genere certificados individuales o masivos con plantillas SVG personalizadas</p>
        </div>
        
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
        
        <!-- Mostrar información del participante individual si aplica -->
        <?php if ($participante_individual && !$error): ?>
            <div class="participante-info">
                <h3 style="margin-bottom: 1rem; color: #155724;">👤 Generar Certificado Individual</h3>
                <div class="participante-details">
                    <div class="detail-item">
                        <div class="detail-label">Participante</div>
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
                </div>
                
                <form method="POST" style="margin-top: 1.5rem;">
                    <input type="hidden" name="accion" value="generar_individual">
                    <input type="hidden" name="evento_id" value="<?php echo $participante_individual['evento_id']; ?>">
                    <input type="hidden" name="participante_id" value="<?php echo $participante_individual['id']; ?>">
                    <button type="submit" class="btn btn-primary" onclick="mostrarCargando()">
                        🎨 Generar Certificado Individual
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Selector de evento para estadísticas -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">📊 Estadísticas por Evento</h3>
                <p class="card-description">Seleccione un evento para ver estadísticas detalladas</p>
            </div>
            
            <form method="GET" id="formEstadisticas">
                <div class="form-group">
                    <label for="evento_id_stats">Evento</label>
                    <select id="evento_id_stats" name="evento_id" onchange="this.form.submit()">
                        <option value="">Seleccione un evento para ver estadísticas</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>" 
                                    <?php echo (isset($_GET['evento_id']) && $_GET['evento_id'] == $evento['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($evento['nombre']); ?> 
                                (<?php echo formatearFecha($evento['fecha_inicio']); ?>)
                                - <?php echo $evento['total_participantes']; ?> participantes
                                <?php if ($evento['tiene_plantillas'] > 0): ?>
                                    🎨
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            
            <?php if ($estadisticas_evento): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_evento['total_participantes']; ?></div>
                        <div class="stat-label">Total Participantes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_evento['total_certificados']; ?></div>
                        <div class="stat-label">Certificados Generados</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_evento['pendientes']; ?></div>
                        <div class="stat-label">Pendientes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_evento['total_plantillas']; ?></div>
                        <div class="stat-label">Plantillas SVG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_evento['certificados_svg']; ?></div>
                        <div class="stat-label">Certificados SVG</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas_evento['porcentaje_completado']; ?>%</div>
                        <div class="stat-label">Completado</div>
                    </div>
                </div>
                
                <div class="progress-section">
                    <h4>Progreso de Generación</h4>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $estadisticas_evento['porcentaje_completado']; ?>%"></div>
                    </div>
                    <p style="text-align: center; margin-top: 0.5rem;">
                        <?php echo $estadisticas_evento['total_certificados']; ?> de <?php echo $estadisticas_evento['total_participantes']; ?> certificados generados
                    </p>
                </div>
                
                <?php if (!empty($estadisticas_evento['por_rol'])): ?>
                    <h4 style="margin-bottom: 1rem;">📋 Estadísticas por Rol</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <?php foreach ($estadisticas_evento['por_rol'] as $rol_stat): ?>
                            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 4px solid #667eea;">
                                <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($rol_stat['rol']); ?></div>
                                <div style="font-size: 0.9rem; color: #666;">
                                    <?php echo $rol_stat['con_certificado']; ?>/<?php echo $rol_stat['total']; ?> certificados
                                    <?php if ($rol_stat['tiene_plantilla'] > 0): ?>
                                        | 🎨 Con plantilla SVG
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Generación de certificados -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🎨 Generador de Certificados</h3>
                <p class="card-description">Genere certificados individuales o masivos con plantillas SVG</p>
            </div>
            
            <form method="POST" id="formGenerar">
                <div class="form-group">
                    <label for="evento_id">Evento <span class="required">*</span></label>
                    <select id="evento_id" name="evento_id" required>
                        <option value="">Seleccione un evento</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>" 
                                    data-participantes="<?php echo $evento['total_participantes']; ?>"
                                    data-certificados="<?php echo $evento['total_certificados']; ?>"
                                    data-plantillas="<?php echo $evento['tiene_plantillas']; ?>">
                                <?php echo htmlspecialchars($evento['nombre']); ?> 
                                (<?php echo formatearFecha($evento['fecha_inicio']); ?> - <?php echo formatearFecha($evento['fecha_fin']); ?>)
                                - <?php echo $evento['total_participantes']; ?> participantes
                                <?php if ($evento['tiene_plantillas'] > 0): ?>
                                    🎨 SVG
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="btn-group">
                    <button type="submit" name="accion" value="generar_masivo" class="btn btn-primary" onclick="mostrarCargando()">
                        🚀 Generar Certificados Masivos
                    </button>
                    <a href="../participantes/listar.php" class="btn btn-secondary">
                        👥 Ver Participantes
                    </a>
                    <a href="../eventos/listar.php" class="btn btn-warning">
                        📝 Gestionar Eventos
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Información sobre plantillas -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">ℹ️ Información sobre Plantillas SVG</h3>
            </div>
            <div class="alert alert-info">
                <h4 style="margin-bottom: 1rem;">🎨 Sistema de Plantillas SVG</h4>
                <ul style="margin-left: 1.5rem; line-height: 1.8;">
                    <li><strong>Plantillas por Rol:</strong> Cada rol puede tener su propia plantilla SVG personalizada</li>
                    <li><strong>Plantilla General:</strong> Si no hay plantilla específica, se usa la plantilla "General"</li>
                    <li><strong>Fallback PDF:</strong> Si no hay plantillas SVG, se genera un PDF básico</li>
                    <li><strong>Variables Dinámicas:</strong> Las plantillas SVG soportan múltiples variables como nombres, evento, fechas, etc.</li>
                    <li><strong>Alta Calidad:</strong> Los certificados SVG son vectoriales y se escalan sin pérdida de calidad</li>
                </ul>
                <p style="margin-top: 1rem;">
                    <a href="../eventos/listar.php" style="color: #0c5460; text-decoration: none; font-weight: 600;">
                        → Gestionar plantillas SVG en la sección de Eventos
                    </a>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Overlay de carga -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <h3>Generando Certificados...</h3>
            <p>Por favor espere, este proceso puede tomar varios minutos.</p>
        </div>
    </div>
    
    <script>
        function mostrarCargando() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Actualizar información del evento seleccionado
        document.getElementById('evento_id').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value) {
                const participantes = option.dataset.participantes;
                const certificados = option.dataset.certificados;
                const plantillas = option.dataset.plantillas;
                const pendientes = participantes - certificados;
                
                // Aquí podrías mostrar información adicional del evento seleccionado
                console.log(`Evento seleccionado: ${participantes} participantes, ${certificados} certificados generados, ${pendientes} pendientes`);
            }
        });
        
        // Prevenir envío múltiple del formulario
        document.getElementById('formGenerar').addEventListener('submit', function(e) {
            const submitButtons = this.querySelectorAll('button[type="submit"]');
            submitButtons.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = btn.innerHTML.replace('🚀', '⏳');
            });
        });
        
        // Auto-cerrar alerts después de 10 segundos
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 10000);
    </script>
</body>
</html>