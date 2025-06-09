<?php
// admin/certificados/generar.php - VERSIÓN COMPLETA CON SOPORTE SVG
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
                    $tipo_archivo = $resultado['tipo'] ?? 'pdf';
                    $icono_tipo = $tipo_archivo === 'svg' ? '🎨' : '📄';
                    
                    $success = "✅ <strong>Certificado {$tipo_archivo} generado exitosamente</strong><br>" .
                              "👤 <strong>Participante:</strong> " . $resultado['participante'] . "<br>" .
                              "🔑 <strong>Código:</strong> " . $resultado['codigo'] . "<br>" .
                              "{$icono_tipo} <strong>Archivo:</strong> " . $resultado['archivo'];
                              
                    if (isset($resultado['dimensiones'])) {
                        $success .= "<br>📏 <strong>Dimensiones:</strong> {$resultado['dimensiones']['ancho']}x{$resultado['dimensiones']['alto']}px";
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

// Función para generar certificado individual MEJORADA con SVG
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
        
        // Verificar si hay plantillas SVG disponibles
        $tiene_plantilla_svg = tieneePlantillasSVG($participante['evento_id'], $participante['rol']);
        
        if ($tiene_plantilla_svg) {
            // Generar certificado SVG
            $resultado_certificado = generarCertificadoConPlantillaSVG($participante, $codigo_verificacion);
        } else {
            // Generar certificado PDF básico
            $resultado_certificado = generarPDFCertificadoBasico($participante, $codigo_verificacion);
        }
        
        if (!$resultado_certificado['success']) {
            return ['success' => false, 'error' => 'Error al generar certificado: ' . $resultado_certificado['error']];
        }
        
        // Insertar registro en la base de datos
        $stmt = $db->prepare("
            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, dimensiones)
            VALUES (?, ?, ?, ?, ?, ?, ?)
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
            'tiempo_generacion' => round(microtime(true) - $tiempo_inicio, 3)
        ]);
        
        return [
            'success' => true,
            'participante' => $participante['nombres'] . ' ' . $participante['apellidos'],
            'codigo' => $codigo_verificacion,
            'archivo' => $resultado_certificado['nombre_archivo'],
            'tipo' => $tipo_archivo,
            'certificado_id' => $certificado_id,
            'dimensiones' => $resultado_certificado['dimensiones'] ?? null
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
        $tipos_generados = ['svg' => 0, 'pdf' => 0];
        
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
                        $tipo = $resultado['tipo'] ?? 'pdf';
                        $tipos_generados[$tipo]++;
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
            'tipos_generados' => $tipos_generados,
            'detalles_errores' => array_slice($detalles_errores, 0, 10) // Máximo 10 errores mostrados
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función para verificar plantillas SVG disponibles
function tieneePlantillasSVG($evento_id, $rol = null) {
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($rol) {
            // Buscar plantilla específica para el rol o general
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM plantillas_certificados 
                WHERE evento_id = ? AND (rol = ? OR rol = 'General')
            ");
            $stmt->execute([$evento_id, $rol]);
        } else {
            // Buscar cualquier plantilla para el evento
            $stmt = $db->prepare("SELECT COUNT(*) FROM plantillas_certificados WHERE evento_id = ?");
            $stmt->execute([$evento_id]);
        }
        
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Función para generar certificado con plantilla SVG
function generarCertificadoConPlantillaSVG($participante, $codigo_verificacion) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Buscar plantilla específica para el rol del participante
        $stmt = $db->prepare("
            SELECT * FROM plantillas_certificados 
            WHERE evento_id = ? AND rol = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$participante['evento_id'], $participante['rol']]);
        $plantilla = $stmt->fetch();
        
        if (!$plantilla) {
            // Si no hay plantilla específica, buscar plantilla general
            $stmt = $db->prepare("
                SELECT * FROM plantillas_certificados 
                WHERE evento_id = ? AND rol = 'General' 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$participante['evento_id']]);
            $plantilla = $stmt->fetch();
        }
        
        if (!$plantilla) {
            throw new Exception("No se encontró plantilla SVG para este participante");
        }
        
        // Leer plantilla SVG
        $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
        if (!file_exists($ruta_plantilla)) {
            throw new Exception("Archivo de plantilla SVG no encontrado: " . $plantilla['archivo_plantilla']);
        }
        
        $contenido_svg = file_get_contents($ruta_plantilla);
        
        // Preparar datos para reemplazar variables
        $datos_certificado = [
            '{{nombres}}' => htmlspecialchars($participante['nombres'], ENT_XML1, 'UTF-8'),
            '{{apellidos}}' => htmlspecialchars($participante['apellidos'], ENT_XML1, 'UTF-8'),
            '{{numero_identificacion}}' => htmlspecialchars($participante['numero_identificacion'], ENT_XML1, 'UTF-8'),
            '{{correo_electronico}}' => htmlspecialchars($participante['correo_electronico'], ENT_XML1, 'UTF-8'),
            '{{rol}}' => htmlspecialchars($participante['rol'], ENT_XML1, 'UTF-8'),
            '{{telefono}}' => htmlspecialchars($participante['telefono'] ?: '', ENT_XML1, 'UTF-8'),
            '{{institucion}}' => htmlspecialchars($participante['institucion'] ?: '', ENT_XML1, 'UTF-8'),
            '{{evento_nombre}}' => htmlspecialchars($participante['evento_nombre'], ENT_XML1, 'UTF-8'),
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
            '{{firma_digital}}' => 'Certificado Digital Verificado'
        ];
        
        // Reemplazar variables en el SVG
        $svg_procesado = $contenido_svg;
        foreach ($datos_certificado as $variable => $valor) {
            $svg_procesado = str_replace($variable, $valor, $svg_procesado);
        }
        
        // Generar nombre de archivo único
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
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Función para generar PDF básico (fallback)
function generarPDFCertificadoBasico($participante, $codigo_verificacion) {
    try {
        // Generar nombre de archivo único
        $nombre_archivo = $codigo_verificacion . '_' . time() . '.pdf';
        $ruta_completa = GENERATED_PATH . 'certificados/' . $nombre_archivo;
        
        // Asegurar que el directorio existe
        if (!is_dir(GENERATED_PATH . 'certificados/')) {
            mkdir(GENERATED_PATH . 'certificados/', 0755, true);
        }
        
        // Generar contenido del PDF básico
        $contenido_pdf = generarContenidoPDFBasico($participante, $codigo_verificacion);
        
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

// Función para generar contenido PDF básico
function generarContenidoPDFBasico($participante, $codigo_verificacion) {
    // Preparar texto del certificado
    $nombre_completo = strtoupper($participante['nombres'] . ' ' . $participante['apellidos']);
    $evento_nombre = strtoupper($participante['evento_nombre']);
    $entidad = $participante['entidad_organizadora'];
    $fecha_inicio = formatearFecha($participante['fecha_inicio']);
    $fecha_fin = formatearFecha($participante['fecha_fin']);
    $rol = $participante['rol'];
    $modalidad = ucfirst($participante['modalidad']);
    
    $duracion_texto = $participante['horas_duracion'] ? 
        "\\nDuracion: " . $participante['horas_duracion'] . " horas academicas" : "";
    
    $lugar_texto = $participante['lugar'] ? 
        "\\nLugar: " . $participante['lugar'] : "";
    
    $modalidad_texto = "\\nModalidad: " . $modalidad;
    
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
    
    // Generar PDF con estructura básica
    $contenido = "%PDF-1.4\n";
    $contenido .= "1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n\n";
    $contenido .= "2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n\n";
    $contenido .= "3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 842 595]\n/Contents 4 0 R\n";
    $contenido .= "/Resources <<\n/Font <<\n/F1 5 0 R\n/F2 6 0 R\n>>\n>>\n>>\nendobj\n\n";
    
    $contenido .= "4 0 obj\n<<\n/Length $longitud_contenido\n>>\nstream\nBT\n";
    $contenido .= "/F2 24 Tf\n70 500 Td\n(CERTIFICADO DE PARTICIPACION) Tj\n";
    $contenido .= "/F1 14 Tf\n0 -60 Td\n";
    $contenido .= "($texto_certificado) Tj\n";
    $contenido .= "ET\nendstream\nendobj\n\n";
    
    $contenido .= "5 0 obj\n<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica\n>>\nendobj\n\n";
    $contenido .= "6 0 obj\n<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /Helvetica-Bold\n>>\nendobj\n\n";
    
    $contenido .= "xref\n0 7\n";
    $contenido .= "0000000000 65535 f \n";
    $contenido .= "0000000010 65535 n \n";
    $contenido .= "0000000053 65535 n \n";
    $contenido .= "0000000125 65535 n \n";
    $contenido .= "0000000348 65535 n \n";
    $contenido .= sprintf("%010d 00000 n \n", strlen($contenido) + 50);
    $contenido .= sprintf("%010d 00000 n \n", strlen($contenido) + 100);
    
    $contenido .= "trailer\n<<\n/Size 7\n/Root 1 0 R\n>>\n";
    $contenido .= "startxref\n" . (strlen($contenido) + 150) . "\n%%EOF\n";
    
    return $contenido;
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
        'salt' => 'certificados_digitales_svg_2025_' . date('Y-m-d'),
        'version' => '3.0'
    ];
    
    return hash('sha256', json_encode($datos_validacion, JSON_UNESCAPED_UNICODE));
}
?>
