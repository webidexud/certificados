<?php
// admin/certificados/generar.php - CORREGIDO SIN FUNCIÓN DUPLICADA
require_once '../../config/config.php';
require_once '../../includes/funciones.php';
require_once '../../includes/funciones_svg.php'; // Incluir funciones SVG

verificarAutenticacion();

$error = '';
$success = '';
$generando = false;

// Obtener lista de eventos activos
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, nombre, fecha_inicio, fecha_fin FROM eventos WHERE estado = 'activo' ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

// Procesar formulario
if ($_POST && isset($_POST['evento_id'])) {
    $evento_id = intval($_POST['evento_id']);
    
    if (empty($evento_id)) {
        $error = 'Por favor, seleccione un evento';
    } else {
        $generando = true;
        
        // Generar certificados para el evento seleccionado
        $resultado = generarCertificadosEvento($evento_id);
        
        if ($resultado['success']) {
            $success = $resultado['mensaje'];
        } else {
            $error = $resultado['error'];
        }
        
        $generando = false;
    }
}

function generarCertificadosEvento($evento_id) {
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
                            INSERT INTO certificados (participante_id, evento_id, codigo_verificacion, archivo_pdf, hash_validacion, tipo_archivo, dimensiones, fecha_generacion)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        $tipo_archivo = $resultado_certificado['tipo'] ?? 'pdf';
                        $dimensiones_json = isset($resultado_certificado['dimensiones']) ? 
                            json_encode($resultado_certificado['dimensiones']) : null;
                        
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
                        
                        // Registrar auditoría
                        registrarAuditoria('GENERAR_CERTIFICADO', 'certificados', $db->lastInsertId(), null, [
                            'participante_id' => $participante['id'],
                            'codigo_verificacion' => $codigo_verificacion,
                            'tipo_archivo' => $tipo_archivo
                        ]);
                        
                    } else {
                        $errores++;
                        $detalles_errores[] = "Error para {$participante['nombres']} {$participante['apellidos']}: " . $resultado_certificado['error'];
                    }
                    
                } catch (Exception $e) {
                    $errores++;
                    $detalles_errores[] = "Error para {$participante['nombres']} {$participante['apellidos']}: " . $e->getMessage();
                }
            }
        }
        
        // Mensaje de resultado
        $mensaje = "✅ Certificados generados exitosamente:\n";
        $mensaje .= "• Total generados: {$generados}\n";
        $mensaje .= "• Certificados SVG: {$tipos_generados['svg']}\n";
        $mensaje .= "• Certificados PDF: {$tipos_generados['pdf']}\n";
        
        if ($errores > 0) {
            $mensaje .= "\n⚠️ Errores encontrados: {$errores}\n";
            $mensaje .= "Primeros errores:\n" . implode("\n", array_slice($detalles_errores, 0, 3));
        }
        
        return ['success' => true, 'mensaje' => $mensaje];
        
    } catch (Exception $e) {
        error_log("Error generando certificados: " . $e->getMessage());
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

function generarCertificadoConPlantillaSVG($participante, $codigo_verificacion, $plantilla) {
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
        
        // Optimizar SVG para mejor renderizado (función de funciones_svg.php)
        $contenido_svg = optimizarSVGTexto($contenido_svg);
        
        // PROCESAR NOMBRES LARGOS PRIMERO (MANEJO ESPECIAL)
        $contenido_svg = procesarNombresLargos($contenido_svg, $participante['nombres'], $participante['apellidos']);
        
        // PROCESAR EVENTO LARGO (MANEJO ESPECIAL)  
        $contenido_svg = procesarEventosLargos($contenido_svg, $participante['evento_nombre']);
        
        // Preparar datos para variables - INCLUYENDO NÚMERO DE IDENTIFICACIÓN
        $datos_certificado = [
            // DATOS BÁSICOS DEL PARTICIPANTE
            '{{numero_identificacion}}' => htmlspecialchars($participante['numero_identificacion'], ENT_XML1, 'UTF-8'),
            '{{correo_electronico}}' => htmlspecialchars($participante['correo_electronico'], ENT_XML1, 'UTF-8'),
            '{{rol}}' => htmlspecialchars($participante['rol'], ENT_XML1, 'UTF-8'),
            '{{telefono}}' => htmlspecialchars($participante['telefono'] ?: '', ENT_XML1, 'UTF-8'),
            '{{institucion}}' => htmlspecialchars($participante['institucion'] ?: '', ENT_XML1, 'UTF-8'),
            
            // DATOS DEL EVENTO
            '{{evento_descripcion}}' => htmlspecialchars($participante['descripcion'] ?: '', ENT_XML1, 'UTF-8'),
            '{{fecha_inicio}}' => formatearFecha($participante['fecha_inicio']),
            '{{fecha_fin}}' => formatearFecha($participante['fecha_fin']),
            '{{entidad_organizadora}}' => htmlspecialchars($participante['entidad_organizadora'], ENT_XML1, 'UTF-8'),
            '{{modalidad}}' => ucfirst($participante['modalidad']),
            '{{lugar}}' => htmlspecialchars($participante['lugar'] ?: 'Virtual', ENT_XML1, 'UTF-8'),
            '{{horas_duracion}}' => $participante['horas_duracion'] ?: '0',
            
            // DATOS DEL CERTIFICADO
            '{{codigo_verificacion}}' => $codigo_verificacion,
            '{{fecha_generacion}}' => date('d/m/Y H:i'),
            '{{fecha_emision}}' => date('d/m/Y'),
            '{{año}}' => date('Y'),
            '{{mes}}' => date('m'),
            '{{dia}}' => date('d'),
            
            // URLs Y ENLACES
            '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion,
            '{{numero_certificado}}' => 'CERT-' . date('Y') . '-' . str_pad($participante['id'], 6, '0', STR_PAD_LEFT),
            
            // EXTRAS
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
        
        // GENERAR CÓDIGO QR - NUEVA FUNCIONALIDAD
        $contenido_svg = generarCodigoQREnSVG($contenido_svg, $codigo_verificacion);
        
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
    $fecha_actual = date('d/m/Y');
    
    return "CERTIFICADO DE PARTICIPACIÓN

UNIVERSIDAD DISTRITAL FRANCISCO JOSÉ DE CALDAS
SISTEMA DE GESTIÓN DE PROYECTOS Y OFICINA DE EXTENSIÓN (SGPOE)

Se certifica que:

{$nombre_completo}
Documento de Identidad: {$participante['numero_identificacion']}

Participó exitosamente en el evento:

{$participante['evento_nombre']}

Realizado del " . formatearFecha($participante['fecha_inicio']) . " al " . formatearFecha($participante['fecha_fin']) . "
Modalidad: " . ucfirst($participante['modalidad']) . "
Lugar: " . ($participante['lugar'] ?: 'Virtual') . "
Entidad Organizadora: {$participante['entidad_organizadora']}
Duración: " . ($participante['horas_duracion'] ?: 'No especificada') . " horas académicas

En calidad de: {$participante['rol']}

Expedido en Bogotá D.C., a los {$fecha_actual}

Código de Verificación: {$codigo_verificacion}
Consulte la autenticidad en: " . PUBLIC_URL . "verificar.php

Este es un certificado digital generado automáticamente.
Para verificar su autenticidad, ingrese el código de verificación en nuestro sitio web.

---
SGPOE - Universidad Distrital Francisco José de Caldas
" . date('Y');
}

function generarHashValidacion($participante, $codigo_verificacion) {
    $datos_hash = $participante['id'] . $participante['numero_identificacion'] . $codigo_verificacion . date('Y-m-d');
    return hash('sha256', $datos_hash);
}

function obtenerNombreMes($numero_mes) {
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    return $meses[$numero_mes] ?? 'Mes';
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

// FUNCIÓN REMOVIDA - SE USA LA DE funciones_svg.php
// function optimizarSVGTexto($contenido_svg) - ELIMINADA PARA EVITAR CONFLICTO

// Nueva función para generar código QR en SVG
function generarCodigoQREnSVG($contenido_svg, $codigo_verificacion) {
    // URL completa para el QR
    $url_verificacion = PUBLIC_URL . 'verificar.php?codigo=' . $codigo_verificacion;
    
    // Buscar elementos existentes del QR en el SVG y reemplazar con QR real
    // Por ahora, mantenemos el QR simulado pero agregamos la URL
    $patron_qr = '/(<g[^>]*transform="translate\([^)]+\)"[^>]*>.*?<!-- Simulación de código QR.*?<\/g>)/s';
    
    if (preg_match($patron_qr, $contenido_svg, $matches)) {
        $qr_mejorado = generarQRSimuladoMejorado($codigo_verificacion);
        $contenido_svg = str_replace($matches[1], $qr_mejorado, $contenido_svg);
    }
    
    // Reemplazar URLs dinámicamente
    $contenido_svg = str_replace('{{url_verificacion}}', htmlspecialchars($url_verificacion, ENT_XML1), $contenido_svg);
    
    return $contenido_svg;
}

// Función para generar QR simulado mejorado
function generarQRSimuladoMejorado($codigo) {
    // Crear un patrón QR más realista basado en el código
    $seed = crc32($codigo);
    mt_srand($seed);
    
    $qr_svg = '<g transform="translate(950, 580)">
        <!-- Marco del código QR -->
        <rect x="0" y="0" width="80" height="80" rx="4" ry="4" 
              fill="white" stroke="#d1d5db" stroke-width="2"/>
        
        <!-- QR Pattern generado dinámicamente -->
        <g fill="#1f2937">';
    
    // Esquinas fijas del QR
    $qr_svg .= '
            <rect x="8" y="8" width="16" height="16" rx="2"/>
            <rect x="56" y="8" width="16" height="16" rx="2"/>
            <rect x="8" y="56" width="16" height="16" rx="2"/>';
    
    // Generar patrón pseudo-aleatorio basado en el código
    for ($i = 0; $i < 20; $i++) {
        $x = 10 + (mt_rand() % 15) * 4;
        $y = 30 + (mt_rand() % 10) * 2;
        $size = mt_rand(1, 3);
        $qr_svg .= "\n            <rect x=\"{$x}\" y=\"{$y}\" width=\"{$size}\" height=\"{$size}\"/>";
    }
    
    $qr_svg .= '
        </g>
        
        <!-- Texto de verificación -->
        <text x="40" y="100" text-anchor="middle" fill="#6b7280" 
              font-family="Arial, sans-serif" font-size="10" font-weight="600">
          VERIFICAR
        </text>
    </g>';
    
    return $qr_svg;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Certificados - Sistema IDEXUD</title>
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
            max-width: 1000px;
            margin: 20px auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 120, 135, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #007887 0%, #3db8ab 50%, #68d6ca 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 10px;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .alert {
            padding: 20px 25px;
            margin: 25px 0;
            border-radius: 15px;
            border: none;
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
        
        .form-group select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }
        
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
            font-size: 1rem;
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
        
        .btn-warning {
            background: linear-gradient(135deg, #f9a826 0%, #f39c12 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(249, 168, 38, 0.3);
        }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 120, 135, 0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .info-card {
            background: linear-gradient(135deg, #007887 0%, #07796b 50%, #3db8ab 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin: 30px 0;
        }
        
        .info-card h3 {
            margin-bottom: 15px;
            font-size: 1.5rem;
        }
        
        .nav-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Generador de Certificados</h1>
            <p>Sistema de Gestión de Proyectos y Oficina de Extensión (SGPOE)</p>
            <p style="opacity: 0.9;">Universidad Distrital Francisco José de Caldas</p>
        </div>
        
        <div class="content">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>❌ Error:</strong> <?php echo nl2br(htmlspecialchars($error)); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>✅ Éxito:</strong> <?php echo nl2br(htmlspecialchars($success)); ?>
                </div>
            <?php endif; ?>
            
            <div class="info-card">
                <h3>ℹ️ Sistema de Plantillas SVG</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 10px;">🎨 Plantillas por Rol</h4>
                        <p>Cada rol puede tener su propia plantilla SVG personalizada con identidad IDEXUD.</p>
                    </div>
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 10px;">📋 Plantilla General</h4>
                        <p>Si no hay plantilla específica, se usa la plantilla "General" como respaldo.</p>
                    </div>
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 10px;">📄 Fallback PDF</h4>
                        <p>Si no hay plantillas SVG, se genera un PDF básico automáticamente.</p>
                    </div>
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 10px;">⚡ Alta Calidad</h4>
                        <p>Los certificados SVG son vectoriales y se escalan sin pérdida de calidad.</p>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h2 style="color: #007887; margin-bottom: 25px; text-align: center;">Seleccionar Evento para Generar Certificados</h2>
                
                <form method="POST" onsubmit="return confirmarGeneracion()">
                    <div class="form-group">
                        <label for="evento_id">Evento <span style="color: #e63946;">*</span></label>
                        <select id="evento_id" name="evento_id" required>
                            <option value="">Seleccione un evento</option>
                            <?php foreach ($eventos as $evento): ?>
                                <option value="<?php echo $evento['id']; ?>">
                                    <?php echo htmlspecialchars($evento['nombre']); ?> 
                                    (<?php echo formatearFecha($evento['fecha_inicio']); ?> - <?php echo formatearFecha($evento['fecha_fin']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="text-align: center; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" onclick="mostrarCargando()">
                            🚀 Generar Certificados Masivos
                        </button>
                    </div>
                </form>
                
                <div class="nav-buttons">
                    <a href="../participantes/listar.php" class="btn btn-secondary">
                        👥 Ver Participantes
                    </a>
                    <a href="../eventos/listar.php" class="btn btn-warning">
                        📝 Gestionar Eventos
                    </a>
                </div>
            </div>
            
            <div class="info-card">
                <h3>🔧 Variables Dinámicas Disponibles</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-top: 20px;">
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 15px;">👤 Datos del Participante</h4>
                        <ul style="list-style: none; padding: 0;">
                            <li style="margin-bottom: 5px;">• {{nombres}} {{apellidos}}</li>
                            <li style="margin-bottom: 5px;">• {{numero_identificacion}}</li>
                            <li style="margin-bottom: 5px;">• {{correo_electronico}}</li>
                            <li style="margin-bottom: 5px;">• {{rol}} {{telefono}}</li>
                            <li style="margin-bottom: 5px;">• {{institucion}}</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 15px;">📅 Datos del Evento</h4>
                        <ul style="list-style: none; padding: 0;">
                            <li style="margin-bottom: 5px;">• {{evento_nombre}}</li>
                            <li style="margin-bottom: 5px;">• {{fecha_inicio}} {{fecha_fin}}</li>
                            <li style="margin-bottom: 5px;">• {{entidad_organizadora}}</li>
                            <li style="margin-bottom: 5px;">• {{modalidad}} {{lugar}}</li>
                            <li style="margin-bottom: 5px;">• {{horas_duracion}}</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h4 style="color: rgba(255,255,255,0.9); margin-bottom: 15px;">🏆 Datos del Certificado</h4>
                        <ul style="list-style: none; padding: 0;">
                            <li style="margin-bottom: 5px;">• {{codigo_verificacion}}</li>
                            <li style="margin-bottom: 5px;">• {{numero_certificado}}</li>
                            <li style="margin-bottom: 5px;">• {{fecha_generacion}}</li>
                            <li style="margin-bottom: 5px;">• {{url_verificacion}}</li>
                            <li style="margin-bottom: 5px;">• {{año}} {{mes}} {{dia}}</li>
                        </ul>
                    </div>
                </div>
                
                <div style="margin-top: 25px; text-align: center;">
                    <p style="font-size: 1.1rem; opacity: 0.9;">
                        <strong>💡 Consejo:</strong> Configure plantillas SVG desde la sección "Plantillas" en cada evento para obtener certificados personalizados con la identidad institucional de IDEXUD.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Overlay de carga -->
    <div class="loading-overlay" id="loadingOverlay">
        <div style="text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 20px; animation: spin 2s linear infinite;">⚙️</div>
            <div style="font-size: 1.5rem; margin-bottom: 10px;">Generando Certificados</div>
            <div style="font-size: 1rem; opacity: 0.8;">Por favor espere, este proceso puede tomar varios minutos...</div>
            <div style="margin-top: 20px; background: rgba(255,255,255,0.2); border-radius: 10px; padding: 10px;">
                <div style="font-size: 0.9rem;">🎨 Procesando plantillas SVG</div>
                <div style="font-size: 0.9rem;">📄 Generando certificados PDF</div>
                <div style="font-size: 0.9rem;">💾 Guardando en base de datos</div>
            </div>
        </div>
    </div>
    
    <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
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
            
            .nav-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
    
    <script>
        function mostrarCargando() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.add('active');
        }
        
        function confirmarGeneracion() {
            const eventoSelect = document.getElementById('evento_id');
            const eventoNombre = eventoSelect.options[eventoSelect.selectedIndex].text;
            
            if (!eventoSelect.value) {
                alert('❌ Por favor seleccione un evento antes de continuar');
                return false;
            }
            
            const confirmacion = confirm(
                `🚀 CONFIRMACIÓN DE GENERACIÓN MASIVA\n\n` +
                `Evento seleccionado: ${eventoNombre}\n\n` +
                `Esta acción generará certificados para TODOS los participantes sin certificado en este evento.\n\n` +
                `El proceso puede tomar varios minutos dependiendo del número de participantes.\n\n` +
                `¿Está seguro de continuar?`
            );
            
            if (confirmacion) {
                mostrarCargando();
                return true;
            }
            
            return false;
        }
        
        // Auto-ocultar mensajes después de unos segundos
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.classList.contains('alert-success')) {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.style.display = 'none', 500);
                }
            });
        }, 8000);
        
        // Prevenir doble envío de formulario
        document.querySelector('form').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Generando...';
        });
    </script>
</body>
</html>