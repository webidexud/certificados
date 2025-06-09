<?php
// admin/eventos/preview_plantilla.php - VERSIÓN SVG
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$plantilla_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$plantilla_id) {
    die('ID de plantilla no válido');
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener información de la plantilla
    $stmt = $db->prepare("
        SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin, 
               e.entidad_organizadora, e.modalidad, e.lugar, e.horas_duracion
        FROM plantillas_certificados p
        JOIN eventos e ON p.evento_id = e.id
        WHERE p.id = ?
    ");
    $stmt->execute([$plantilla_id]);
    $plantilla = $stmt->fetch();
    
    if (!$plantilla) {
        die('Plantilla no encontrada');
    }
    
    // Leer contenido del archivo SVG
    $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
    
    if (!file_exists($ruta_plantilla)) {
        die('Archivo de plantilla SVG no encontrado: ' . $plantilla['archivo_plantilla']);
    }
    
    $contenido_svg = file_get_contents($ruta_plantilla);
    
    // Datos de ejemplo para la vista previa
    $datos_ejemplo = [
        '{{nombres}}' => 'Juan Carlos',
        '{{apellidos}}' => 'Pérez García',
        '{{numero_identificacion}}' => '12345678',
        '{{correo_electronico}}' => 'juan.perez@ejemplo.com',
        '{{rol}}' => $plantilla['rol'],
        '{{telefono}}' => '+57 300 123 4567',
        '{{institucion}}' => 'Universidad Ejemplo',
        '{{evento_nombre}}' => $plantilla['evento_nombre'],
        '{{evento_descripcion}}' => 'Descripción detallada del evento académico',
        '{{fecha_inicio}}' => formatearFecha($plantilla['fecha_inicio']),
        '{{fecha_fin}}' => formatearFecha($plantilla['fecha_fin']),
        '{{entidad_organizadora}}' => $plantilla['entidad_organizadora'],
        '{{modalidad}}' => ucfirst($plantilla['modalidad']),
        '{{lugar}}' => $plantilla['lugar'] ?: 'Plataforma Virtual',
        '{{horas_duracion}}' => $plantilla['horas_duracion'] ?: '20',
        '{{codigo_verificacion}}' => 'ABC123XYZ789',
        '{{fecha_generacion}}' => date('d/m/Y H:i'),
        '{{fecha_emision}}' => date('d/m/Y'),
        '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=ABC123XYZ789',
        '{{numero_certificado}}' => 'CERT-2025-001',
        '{{año}}' => date('Y'),
        '{{mes}}' => date('m'),
        '{{dia}}' => date('d'),
        '{{firma_digital}}' => 'Firma Digital Verificada'
    ];
    
    // Reemplazar variables en el contenido SVG
    $svg_procesado = $contenido_svg;
    foreach ($datos_ejemplo as $variable => $valor) {
        $svg_procesado = str_replace($variable, $valor, $svg_procesado);
    }
    
    // Registrar vista previa en auditoría
    registrarAuditoria('PREVIEW_SVG', 'plantillas_certificados', $plantilla_id, null, [
        'plantilla' => $plantilla['nombre_plantilla'],
        'rol' => $plantilla['rol'],
        'tipo' => 'svg'
    ]);
    
} catch (Exception $e) {
    die('Error al cargar la plantilla SVG: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vista Previa SVG - <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .preview-header {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #667eea;
        }
        
        .preview-info h2 {
            margin: 0;
            color: #333;
            font-size: 1.4rem;
        }
        
        .preview-info p {
            margin: 0.5rem 0 0 0;
            color: #666;
            font-size: 0.9rem;
        }
        
        .preview-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .preview-actions {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        
        .certificate-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            max-width: 100%;
            margin: 0 auto;
            border: 1px solid rgba(0,0,0,0.1);
        }
        
        .certificate-frame {
            padding: 30px;
            background: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
        }
        
        .print-notice {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #ffc107;
            color: #856404;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }
        
        .svg-info {
            background: #e3f2fd;
            border: 2px solid #2196f3;
            color: #1565c0;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            font-size: 0.9rem;
        }
        
        .svg-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .svg-info-item strong {
            color: #0d47a1;
        }
        
        .zoom-controls {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 1rem;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            z-index: 1000;
        }
        
        .zoom-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.3s;
        }
        
        .zoom-btn:hover {
            background: #5a67d8;
        }
        
        .zoom-level {
            text-align: center;
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }
        
        /* Estilos específicos para el SVG */
        .certificate-svg {
            max-width: 100%;
            height: auto;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .variables-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 20px;
        }
        
        .variables-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .variables-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .variable-used {
            background: #28a745;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-family: monospace;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .preview-header,
            .print-notice,
            .svg-info,
            .variables-panel,
            .zoom-controls {
                display: none;
            }
            
            .certificate-container {
                box-shadow: none;
                border-radius: 0;
                border: none;
            }
            
            .certificate-frame {
                padding: 0;
            }
            
            .certificate-svg {
                border: none;
                box-shadow: none;
            }
        }
        
        /* Responsive */
        @media screen and (max-width: 768px) {
            .preview-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .preview-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .svg-info {
                grid-template-columns: 1fr;
            }
            
            .certificate-frame {
                padding: 15px;
            }
            
            .zoom-controls {
                position: static;
                margin: 20px auto 0;
                width: fit-content;
                flex-direction: row;
            }
        }
    </style>
</head>
<body>
    <div class="preview-header">
        <div class="preview-info">
            <h2>🎨 Vista Previa SVG: <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?></h2>
            <p><strong>Evento:</strong> <?php echo htmlspecialchars($plantilla['evento_nombre']); ?> | <strong>Rol:</strong> <?php echo htmlspecialchars($plantilla['rol']); ?></p>
        </div>
        <div class="preview-badge">
            Plantilla SVG
        </div>
        <div class="preview-actions">
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ Imprimir
            </button>
            <button onclick="descargarSVG()" class="btn btn-success">
                📥 Descargar SVG
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                ❌ Cerrar
            </button>
        </div>
    </div>
    
    <div class="print-notice">
        <strong>💡 Vista Previa con Datos de Ejemplo</strong><br>
        Esta es una vista previa con datos ficticios. Los certificados reales tendrán los datos específicos de cada participante.
    </div>
    
    <div class="svg-info">
        <div class="svg-info-item">
            <span>📏</span> <strong>Dimensiones:</strong> <?php echo $plantilla['ancho']; ?>px × <?php echo $plantilla['alto']; ?>px
        </div>
        <div class="svg-info-item">
            <span>🔧</span> <strong>Variables:</strong> <?php echo count(json_decode($plantilla['variables_disponibles'], true) ?: []); ?> encontradas
        </div>
        <div class="svg-info-item">
            <span>📁</span> <strong>Archivo:</strong> <?php echo htmlspecialchars($plantilla['archivo_plantilla']); ?>
        </div>
        <div class="svg-info-item">
            <span>📅</span> <strong>Creado:</strong> <?php echo formatearFecha($plantilla['created_at'], 'd/m/Y H:i'); ?>
        </div>
    </div>
    
    <div class="variables-panel">
        <div class="variables-title">🏷️ Variables utilizadas en esta plantilla:</div>
        <div class="variables-list">
            <?php 
            $variables = json_decode($plantilla['variables_disponibles'], true) ?: [];
            foreach ($variables as $variable): 
            ?>
                <span class="variable-used">{{<?php echo htmlspecialchars($variable); ?>}}</span>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="certificate-container">
        <div class="certificate-frame">
            <div class="certificate-svg" id="certificateSvg">
                <?php echo $svg_procesado; ?>
            </div>
        </div>
    </div>
    
    <div class="zoom-controls">
        <button class="zoom-btn" onclick="cambiarZoom(1.2)" title="Acercar">🔍+</button>
        <div class="zoom-level" id="zoomLevel">100%</div>
        <button class="zoom-btn" onclick="cambiarZoom(0.8)" title="Alejar">🔍-</button>
        <button class="zoom-btn" onclick="resetZoom()" title="Restablecer">↺</button>
    </div>
    
    <script>
        let currentZoom = 1;
        
        // Función para cambiar el zoom del SVG
        function cambiarZoom(factor) {
            currentZoom *= factor;
            currentZoom = Math.max(0.3, Math.min(3, currentZoom)); // Limitar zoom entre 30% y 300%
            
            const svg = document.querySelector('#certificateSvg svg');
            if (svg) {
                svg.style.transform = `scale(${currentZoom})`;
                svg.style.transformOrigin = 'center center';
            }
            
            document.getElementById('zoomLevel').textContent = Math.round(currentZoom * 100) + '%';
        }
        
        // Restablecer zoom
        function resetZoom() {
            currentZoom = 1;
            const svg = document.querySelector('#certificateSvg svg');
            if (svg) {
                svg.style.transform = 'scale(1)';
            }
            document.getElementById('zoomLevel').textContent = '100%';
        }
        
        // Descargar SVG
        function descargarSVG() {
            const svgData = `<?php echo addslashes($svg_procesado); ?>`;
            const blob = new Blob([svgData], { type: 'image/svg+xml' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = 'certificado_<?php echo htmlspecialchars($plantilla['rol']); ?>_preview.svg';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Ajustar tamaño inicial del SVG
        window.addEventListener('load', function() {
            const container = document.querySelector('.certificate-frame');
            const svg = document.querySelector('#certificateSvg svg');
            
            if (svg && container) {
                const containerWidth = container.offsetWidth - 60; // Padding
                const svgWidth = <?php echo $plantilla['ancho']; ?>;
                
                if (svgWidth > containerWidth) {
                    const scale = containerWidth / svgWidth;
                    currentZoom = scale;
                    svg.style.transform = `scale(${scale})`;
                    svg.style.transformOrigin = 'center center';
                    document.getElementById('zoomLevel').textContent = Math.round(scale * 100) + '%';
                }
                
                // Añadir clase para mejorar la visualización
                svg.style.maxWidth = '100%';
                svg.style.height = 'auto';
            }
        });
        
        // Zoom con rueda del mouse
        document.addEventListener('wheel', function(e) {
            if (e.ctrlKey) {
                e.preventDefault();
                const factor = e.deltaY > 0 ? 0.9 : 1.1;
                cambiarZoom(factor);
            }
        });
        
        // Atajos de teclado
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey) {
                switch(e.key) {
                    case '+':
                    case '=':
                        e.preventDefault();
                        cambiarZoom(1.1);
                        break;
                    case '-':
                        e.preventDefault();
                        cambiarZoom(0.9);
                        break;
                    case '0':
                        e.preventDefault();
                        resetZoom();
                        break;
                }
            }
        });
        
        // Mejorar calidad del SVG
        window.addEventListener('load', function() {
            const svgs = document.querySelectorAll('svg');
            svgs.forEach(svg => {
                svg.style.shapeRendering = 'geometricPrecision';
                svg.style.textRendering = 'geometricPrecision';
            });
        });
    </script>
</body>
</html>