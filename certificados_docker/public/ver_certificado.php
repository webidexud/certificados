<?php
// public/ver_certificado.php - Optimizado para impresión automática
require_once '../config/config.php';
require_once '../includes/funciones.php';

$error = '';

if (!isset($_GET['codigo'])) {
    $error = 'Código de verificación no proporcionado';
} else {
    $codigo_verificacion = limpiarDatos($_GET['codigo']);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Verificar que el certificado existe y obtener información
        $stmt = $db->prepare("
            SELECT 
                c.archivo_pdf,
                c.tipo_archivo,
                c.dimensiones,
                c.id as certificado_id,
                p.nombres,
                p.apellidos,
                e.nombre as evento_nombre
            FROM certificados c
            JOIN participantes p ON c.participante_id = p.id
            JOIN eventos e ON c.evento_id = e.id
            WHERE c.codigo_verificacion = ?
        ");
        
        $stmt->execute([$codigo_verificacion]);
        $certificado = $stmt->fetch();
        
        if (!$certificado) {
            $error = 'Certificado no encontrado';
        } elseif (empty($certificado['archivo_pdf'])) {
            $error = 'El archivo del certificado no está disponible';
        } else {
            $tipo_archivo = $certificado['tipo_archivo'] ?: 'pdf';
            $ruta_archivo = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
            
            if (!file_exists($ruta_archivo)) {
                $error = 'El archivo del certificado no existe en el servidor';
            } else {
                // Registrar visualización en auditoría
                registrarAuditoria('VISUALIZACION', 'certificados', $certificado['certificado_id'], null, [
                    'codigo_verificacion' => $codigo_verificacion,
                    'tipo_archivo' => $tipo_archivo,
                    'ip_visualizacion' => $_SERVER['REMOTE_ADDR'] ?? 'Desconocida'
                ]);
                
                // Detectar tipo de archivo por contenido
                $contenido = file_get_contents($ruta_archivo);
                $extension = strtolower(pathinfo($certificado['archivo_pdf'], PATHINFO_EXTENSION));
                $es_svg = ($extension === 'svg' || strpos($contenido, '<svg') !== false);
                $es_html = ($extension === 'html' || strpos($contenido, '<!DOCTYPE html') !== false);
                $es_pdf = strpos($contenido, '%PDF-') === 0;
                
                // Si es SVG o HTML, mostrar con estilos optimizados para impresión
                if ($es_svg || $es_html) {
                    ?>
                    <!DOCTYPE html>
                    <html lang="es">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Certificado - <?php echo htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']); ?></title>
                        <style>
                            /* Estilos para pantalla */
                            @media screen {
                                body {
                                    margin: 0;
                                    padding: 20px;
                                    background: #f5f5f5;
                                    font-family: Arial, sans-serif;
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                    min-height: 100vh;
                                }
                                
                                .certificate-container {
                                    background: white;
                                    padding: 20px;
                                    border-radius: 8px;
                                    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                                    max-width: 95%;
                                    max-height: 95%;
                                    overflow: hidden;
                                }
                                
                                .certificate-display {
                                    width: 100%;
                                    height: 100%;
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                }
                                
                                .certificate-display svg {
                                    max-width: 100%;
                                    max-height: 100%;
                                    width: auto;
                                    height: auto;
                                    display: block;
                                }
                                
                                .certificate-content {
                                    width: 100%;
                                    height: 100%;
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                }
                            }
                            
                            /* Estilos para impresión - OPTIMIZADO */
                            @media print {
                                @page {
                                    margin: 0;
                                    size: A4 landscape;
                                }
                                
                                * {
                                    -webkit-print-color-adjust: exact !important;
                                    color-adjust: exact !important;
                                }
                                
                                body {
                                    margin: 0 !important;
                                    padding: 0 !important;
                                    background: white !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                }
                                
                                .certificate-container {
                                    background: white !important;
                                    padding: 0 !important;
                                    margin: 0 !important;
                                    border-radius: 0 !important;
                                    box-shadow: none !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                    max-width: none !important;
                                    max-height: none !important;
                                    overflow: visible !important;
                                }
                                
                                .certificate-display {
                                    margin: 0 !important;
                                    padding: 0 !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                    display: block !important;
                                }
                                
                                .certificate-display svg {
                                    margin: 0 !important;
                                    padding: 0 !important;
                                    width: 100% !important;
                                    height: 100% !important;
                                    max-width: none !important;
                                    max-height: none !important;
                                    display: block !important;
                                }
                                
                                .certificate-content {
                                    width: 100% !important;
                                    height: 100% !important;
                                    margin: 0 !important;
                                    padding: 0 !important;
                                    display: block !important;
                                }
                            }
                            
                            /* Asegurar que los colores se impriman */
                            * {
                                -webkit-print-color-adjust: exact;
                                color-adjust: exact;
                            }
                            
                            /* Ocultar elementos no necesarios durante la impresión */
                            @media print {
                                .no-print {
                                    display: none !important;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="certificate-container">
                            <div class="certificate-display">
                                <div class="certificate-content">
                                    <?php 
                                    if ($es_svg) {
                                        echo $contenido;
                                    } elseif ($es_html) {
                                        // Para HTML, mostrar el contenido directamente
                                        echo $contenido;
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        
                        <script>
                            // Script para optimizar la impresión automática
                            document.addEventListener('DOMContentLoaded', function() {
                                // Ajustar para impresión
                                ajustarParaImpresion();
                                
                                // Notificar al padre que la carga está completa (si estamos en iframe)
                                if (window.parent && window.parent !== window) {
                                    setTimeout(function() {
                                        try {
                                            if (window.parent.manejarCargaCompleta) {
                                                window.parent.manejarCargaCompleta();
                                            }
                                        } catch (e) {
                                            console.log('No se pudo notificar al padre:', e);
                                        }
                                    }, 500);
                                }
                            });
                            
                            function ajustarParaImpresion() {
                                // Envolver el contenido del certificado si no está envuelto
                                const svg = document.querySelector('svg');
                                if (svg && !svg.closest('.certificate-content')) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'certificate-content';
                                    svg.parentNode.insertBefore(wrapper, svg);
                                    wrapper.appendChild(svg);
                                }
                                
                                // Auto-ajuste del contenido en pantalla (similar al código original)
                                window.addEventListener('load', function() {
                                    ajustarTamanoEnPantalla();
                                });
                                
                                window.addEventListener('resize', function() {
                                    ajustarTamanoEnPantalla();
                                });
                            }
                            
                            function ajustarTamanoEnPantalla() {
                                if (!window.matchMedia('print').matches) {
                                    const container = document.querySelector('.certificate-content');
                                    if (container) {
                                        const certificadoAncho = 842; // A4 landscape ancho en px (aprox)
                                        const certificadoAlto = 595;  // A4 landscape alto en px (aprox)
                                        
                                        // Calcular el tamaño máximo manteniendo proporción
                                        const windowWidth = window.innerWidth - 40; // margen 20px cada lado
                                        const windowHeight = window.innerHeight - 40;
                                        
                                        const scaleX = windowWidth / certificadoAncho;
                                        const scaleY = windowHeight / certificadoAlto;
                                        const scale = Math.min(scaleX, scaleY, 1); // No agrandar más del 100%
                                        
                                        const nuevoAncho = certificadoAncho * scale;
                                        const nuevoAlto = certificadoAlto * scale;
                                        
                                        container.style.width = nuevoAncho + 'px';
                                        container.style.height = nuevoAlto + 'px';
                                    }
                                }
                            }
                            
                            // Optimizar para diferentes tipos de certificado
                            window.addEventListener('load', function() {
                                // Asegurar que las fuentes y recursos estén cargados
                                setTimeout(function() {
                                    document.body.classList.add('loaded');
                                }, 100);
                            });
                            
                            // Manejar eventos de impresión si está en iframe
                            window.addEventListener('beforeprint', function() {
                                console.log('Preparando certificado para impresión...');
                                
                                // Ajustar el tamaño específico para impresión
                                const svg = document.querySelector('svg');
                                if (svg) {
                                    svg.style.width = '100%';
                                    svg.style.height = '100%';
                                }
                            });
                            
                            window.addEventListener('afterprint', function() {
                                console.log('Impresión del certificado finalizada');
                            });
                        </script>
                    </body>
                    </html>
                    <?php
                    exit;
                    
                } elseif ($es_pdf) {
                    // Es un PDF, enviarlo directamente al navegador para visualización
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="certificado_' . $codigo_verificacion . '.pdf"');
                    header('Content-Length: ' . filesize($ruta_archivo));
                    header('Cache-Control: private');
                    header('Pragma: private');
                    
                    // Enviar archivo PDF
                    readfile($ruta_archivo);
                    exit;
                    
                } else {
                    $error = 'Tipo de archivo no soportado para visualización';
                }
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error al procesar la visualización: ' . $e->getMessage();
        error_log("Error en ver_certificado.php: " . $e->getMessage());
    }
}

// Si llegamos aquí, hay un error - mostrar página de error
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Sistema de Certificados</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            background: white;
            padding: 3rem;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        .error-icon { font-size: 5rem; color: #dc3545; margin-bottom: 1.5rem; }
        .error-title { color: #333; font-size: 1.8rem; margin-bottom: 1rem; font-weight: 600; }
        .error-message { color: #666; margin-bottom: 2rem; line-height: 1.6; font-size: 1.1rem; }
        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            margin: 0.5rem;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3); }
        .codigo-error {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
            font-family: 'Courier New', monospace;
            color: #dc3545;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">📄❌</div>
        <h2 class="error-title">Error al Visualizar Certificado</h2>
        <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
        
        <?php if (isset($codigo_verificacion)): ?>
            <div class="codigo-error">
                Código consultado: <?php echo htmlspecialchars($codigo_verificacion); ?>
            </div>
        <?php endif; ?>
        
        <div>
            <a href="consulta.php" class="btn">🔍 Consultar Certificados</a>
            <a href="verificar.php" class="btn">✓ Verificar Código</a>
            <a href="index.php" class="btn">🏠 Inicio</a>
        </div>
    </div>
</body>
</html>