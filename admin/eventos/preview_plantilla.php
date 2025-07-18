<?php
// admin/eventos/preview_plantilla.php - Vista previa de plantillas SVG
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$plantilla_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$plantilla_id) {
    die('ID de plantilla no válido');
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener información de la plantilla y evento
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
    
    // Verificar que el archivo existe
    $ruta_plantilla = TEMPLATE_PATH . $plantilla['archivo_plantilla'];
    
    if (!file_exists($ruta_plantilla)) {
        die('Archivo de plantilla no encontrado');
    }
    
    // Leer contenido SVG
    $contenido_svg = file_get_contents($ruta_plantilla);
    
    if (empty($contenido_svg)) {
        die('Error al leer el archivo SVG');
    }
    
    // Datos de ejemplo para el preview
    $datos_ejemplo = [
        // DATOS DEL PARTICIPANTE
        '{{nombres}}' => 'Juan Carlos',
        '{{apellidos}}' => 'García López',
        '{{rol}}' => $plantilla['rol'],
        '{{numero_identificacion}}' => '12.345.678',
        
        // DATOS DEL EVENTO
        '{{evento_nombre}}' => $plantilla['evento_nombre'],
        '{{fecha_inicio}}' => date('d/m/Y', strtotime($plantilla['fecha_inicio'])),
        '{{fecha_fin}}' => date('d/m/Y', strtotime($plantilla['fecha_fin'])),
        '{{entidad_organizadora}}' => $plantilla['entidad_organizadora'] ?: 'Entidad Organizadora',
        '{{modalidad}}' => ucfirst($plantilla['modalidad'] ?: 'presencial'),
        '{{lugar}}' => $plantilla['lugar'] ?: 'Lugar del evento',
        '{{horas_duracion}}' => $plantilla['horas_duracion'] ?: '40',
        
        // DATOS DEL CERTIFICADO
        '{{codigo_verificacion}}' => 'CERT-PREVIEW-2025',
        '{{fecha_generacion}}' => date('d/m/Y H:i'),
        '{{fecha_emision}}' => date('d/m/Y'),
        '{{año}}' => date('Y'),
        '{{mes}}' => date('m'),
        '{{dia}}' => date('d'),
        
        // URLs Y ENLACES
        '{{url_verificacion}}' => PUBLIC_URL . 'verificar.php?codigo=CERT-PREVIEW-2025',
        '{{numero_certificado}}' => 'CERT-' . date('Y') . '-000001',
        
        // EXTRAS
        '{{firma_digital}}' => 'Certificado Digital Verificado',
        '{{mes_nombre}}' => obtenerNombreMes(date('n')),
        '{{año_completo}}' => date('Y'),
        '{{duracion_texto}}' => ($plantilla['horas_duracion'] ?: '40') . ' horas académicas',
        '{{modalidad_completa}}' => 'Modalidad ' . ucfirst($plantilla['modalidad'] ?: 'presencial'),
        '{{nombre_completo}}' => 'Juan Carlos García López',
        '{{iniciales}}' => 'J.C.G.L.',
    ];
    
    // Reemplazar variables en el contenido SVG
    $contenido_preview = $contenido_svg;
    foreach ($datos_ejemplo as $variable => $valor) {
        $contenido_preview = str_replace($variable, $valor, $contenido_preview);
    }
    
    // Limpiar variables no reemplazadas
    $contenido_preview = preg_replace('/\{\{[^}]+\}\}/', '[Variable no definida]', $contenido_preview);
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Función auxiliar
function obtenerNombreMes($numero_mes) {
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    return $meses[$numero_mes] ?? 'Mes';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview - <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: #007bff;
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        
        .info {
            padding: 15px 20px;
            background: #e3f2fd;
            border-bottom: 1px solid #ddd;
        }
        
        .preview-container {
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
        }
        
        .svg-container {
            display: inline-block;
            background: white;
            border: 2px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin: 20px 0;
            max-width: 100%;
            overflow: hidden;
        }
        
        .svg-container svg {
            max-width: 100%;
            height: auto;
            display: block;
        }
        
        .controls {
            margin: 20px 0;
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary { background: #007bff; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-success { background: #28a745; color: white; }
        
        .btn:hover { opacity: 0.8; }
        
        .details {
            padding: 20px;
            border-top: 1px solid #ddd;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .detail-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            border-left: 4px solid #007bff;
        }
        
        .detail-item strong {
            color: #333;
        }
        
        .variables-used {
            margin-top: 20px;
            padding: 15px;
            background: #e3f2fd;
            border-radius: 4px;
        }
        
        .variable-tag {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            margin: 2px;
            font-family: monospace;
        }
        
        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .controls {
                flex-direction: column;
            }
            
            .btn {
                width: 90%;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Vista Previa: <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?></h1>
            <p>Rol: <?php echo htmlspecialchars($plantilla['rol']); ?> | Evento: <?php echo htmlspecialchars($plantilla['evento_nombre']); ?></p>
        </div>
        
        <div class="info">
            <strong>ℹ️ Información:</strong> Esta es una vista previa con datos de ejemplo. 
            En el certificado real se reemplazarán con los datos reales del participante y evento.
        </div>
        
        <div class="preview-container">
            <h2>Vista Previa del Certificado</h2>
            
            <div class="svg-container">
                <?php echo $contenido_preview; ?>
            </div>
            
            <div class="controls">
                <a href="javascript:history.back()" class="btn btn-secondary">← Volver</a>
                <a href="descargar_plantilla.php?id=<?php echo $plantilla_id; ?>" class="btn btn-primary">📥 Descargar SVG Original</a>
                <button onclick="imprimirPreview()" class="btn btn-success">🖨️ Imprimir Preview</button>
                <button onclick="abrirEnNuevaVentana()" class="btn btn-primary">🔗 Abrir en Nueva Ventana</button>
            </div>
        </div>
        
        <div class="details">
            <h3>📋 Detalles de la Plantilla</h3>
            
            <div class="detail-grid">
                <div class="detail-item">
                    <strong>Nombre:</strong><br>
                    <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?>
                </div>
                <div class="detail-item">
                    <strong>Rol:</strong><br>
                    <?php echo htmlspecialchars($plantilla['rol']); ?>
                </div>
                <div class="detail-item">
                    <strong>Dimensiones:</strong><br>
                    <?php echo $plantilla['ancho']; ?> x <?php echo $plantilla['alto']; ?> px
                </div>
                <div class="detail-item">
                    <strong>Archivo:</strong><br>
                    <?php echo htmlspecialchars($plantilla['archivo_plantilla']); ?>
                </div>
                <div class="detail-item">
                    <strong>Creado:</strong><br>
                    <?php echo date('d/m/Y H:i', strtotime($plantilla['created_at'])); ?>
                </div>
                <div class="detail-item">
                    <strong>Actualizado:</strong><br>
                    <?php echo date('d/m/Y H:i', strtotime($plantilla['updated_at'])); ?>
                </div>
            </div>
            
            <div class="variables-used">
                <h4>🔧 Variables utilizadas en esta plantilla:</h4>
                <?php
                // Mostrar variables que fueron reemplazadas
                foreach ($datos_ejemplo as $variable => $valor) {
                    if (strpos($contenido_svg, $variable) !== false) {
                        echo '<span class="variable-tag">' . htmlspecialchars($variable) . '</span>';
                    }
                }
                ?>
            </div>
        </div>
    </div>
    
    <script>
        function imprimirPreview() {
            const contenido = document.querySelector('.svg-container').innerHTML;
            const ventana = window.open('', '_blank');
            ventana.document.write(`
                <html>
                <head>
                    <title>Imprimir Preview - <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?></title>
                    <style>
                        body { margin: 0; padding: 20px; background: white; }
                        svg { max-width: 100%; height: auto; }
                    </style>
                </head>
                <body>
                    ${contenido}
                    <script>window.onload = function() { window.print(); }<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }
        
        function abrirEnNuevaVentana() {
            const contenido = document.querySelector('.svg-container').innerHTML;
            const ventana = window.open('', '_blank', 'width=1200,height=900');
            ventana.document.write(`
                <html>
                <head>
                    <title>Preview - <?php echo htmlspecialchars($plantilla['nombre_plantilla']); ?></title>
                    <style>
                        body { 
                            margin: 0; 
                            padding: 20px; 
                            background: #f5f5f5; 
                            display: flex; 
                            justify-content: center; 
                            align-items: center; 
                            min-height: 100vh; 
                        }
                        .container { 
                            background: white; 
                            padding: 20px; 
                            border-radius: 8px; 
                            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
                        }
                        svg { max-width: 100%; height: auto; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        ${contenido}
                    </div>
                </body>
                </html>
            `);
            ventana.document.close();
        }
        
        // Ajustar tamaño del SVG según el viewport
        window.addEventListener('resize', function() {
            const svgContainer = document.querySelector('.svg-container');
            const svg = svgContainer.querySelector('svg');
            if (svg) {
                const containerWidth = svgContainer.clientWidth - 40; // 40px para padding
                const svgWidth = svg.getAttribute('width') || svg.viewBox?.baseVal.width || 1200;
                
                if (svgWidth > containerWidth) {
                    svg.style.width = '100%';
                    svg.style.height = 'auto';
                }
            }
        });
        
        // Ejecutar al cargar
        window.addEventListener('load', function() {
            window.dispatchEvent(new Event('resize'));
        });
    </script>
</body>
</html>