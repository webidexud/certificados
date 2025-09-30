<?php
// admin/certificados/diagnostico.php - HERRAMIENTA DE DIAGNÓSTICO
require_once '../../config/config.php';
require_once '../../includes/funciones.php';



$participante_id = isset($_GET['participante_id']) ? intval($_GET['participante_id']) : 0;

if (!$participante_id) {
    die('Especifica un participante_id en la URL: ?participante_id=X');
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener certificado
    $stmt = $db->prepare("
        SELECT c.*, p.nombres, p.apellidos, e.nombre as evento_nombre
        FROM certificados c
        JOIN participantes p ON c.participante_id = p.id
        JOIN eventos e ON c.evento_id = e.id
        WHERE c.participante_id = ?
        ORDER BY c.fecha_generacion DESC
        LIMIT 1
    ");
    $stmt->execute([$participante_id]);
    $certificado = $stmt->fetch();
    
    echo "<h1>🔍 Diagnóstico de Certificado</h1>";
    echo "<style>body{font-family:Arial;padding:20px;} .info{background:#f0f8ff;padding:10px;margin:10px 0;border-left:4px solid #0066cc;} .error{background:#fff0f0;padding:10px;margin:10px 0;border-left:4px solid #cc0000;} .success{background:#f0fff0;padding:10px;margin:10px 0;border-left:4px solid #00cc00;} code{background:#f5f5f5;padding:2px 4px;}</style>";
    
    if (!$certificado) {
        echo "<div class='error'><strong>❌ Error:</strong> No se encontró certificado para participante_id = $participante_id</div>";
        exit;
    }
    
    echo "<div class='info'><strong>📋 Información del certificado:</strong><br>";
    echo "Participante: " . htmlspecialchars($certificado['nombres'] . ' ' . $certificado['apellidos']) . "<br>";
    echo "Evento: " . htmlspecialchars($certificado['evento_nombre']) . "<br>";
    echo "Archivo: " . htmlspecialchars($certificado['archivo_pdf']) . "<br>";
    echo "Código: " . htmlspecialchars($certificado['codigo_verificacion']) . "<br>";
    echo "Fecha: " . $certificado['fecha_generacion'] . "</div>";
    
    // Verificar archivo
    $archivo_path = GENERATED_PATH . 'certificados/' . $certificado['archivo_pdf'];
    echo "<div class='info'><strong>📁 Ruta del archivo:</strong><br><code>$archivo_path</code></div>";
    
    if (!file_exists($archivo_path)) {
        echo "<div class='error'><strong>❌ El archivo NO existe</strong></div>";
        echo "<div class='info'><strong>Verificar:</strong><br>";
        echo "1. Que la carpeta <code>" . GENERATED_PATH . "certificados/</code> exista<br>";
        echo "2. Que el archivo <code>" . $certificado['archivo_pdf'] . "</code> esté en esa carpeta<br>";
        echo "3. Permisos de lectura en la carpeta</div>";
        exit;
    }
    
    echo "<div class='success'><strong>✅ El archivo existe</strong></div>";
    
    // Información del archivo
    $tamaño = filesize($archivo_path);
    $extension = pathinfo($certificado['archivo_pdf'], PATHINFO_EXTENSION);
    echo "<div class='info'><strong>📊 Propiedades del archivo:</strong><br>";
    echo "Tamaño: " . number_format($tamaño) . " bytes<br>";
    echo "Extensión: .$extension</div>";
    
    if ($tamaño === 0) {
        echo "<div class='error'><strong>❌ El archivo está vacío (0 bytes)</strong></div>";
        exit;
    }
    
    // Leer contenido
    $contenido = file_get_contents($archivo_path);
    if ($contenido === false) {
        echo "<div class='error'><strong>❌ No se puede leer el contenido del archivo</strong></div>";
        exit;
    }
    
    echo "<div class='success'><strong>✅ Contenido leído correctamente</strong></div>";
    
    // Detectar tipo
    $es_svg = (strtolower($extension) === 'svg' || strpos($contenido, '<svg') !== false);
    $es_pdf = strpos($contenido, '%PDF-') === 0;
    
    echo "<div class='info'><strong>🔍 Análisis del contenido:</strong><br>";
    echo "Es SVG: " . ($es_svg ? '✅ Sí' : '❌ No') . "<br>";
    echo "Es PDF: " . ($es_pdf ? '✅ Sí' : '❌ No') . "<br>";
    echo "Primeros 100 caracteres: <code>" . htmlspecialchars(substr($contenido, 0, 100)) . "...</code></div>";
    
    if (!$es_svg && !$es_pdf) {
        echo "<div class='error'><strong>❌ El archivo no es ni SVG ni PDF válido</strong></div>";
        echo "<div class='info'><strong>Contenido completo (primeros 500 chars):</strong><br><code>" . htmlspecialchars(substr($contenido, 0, 500)) . "</code></div>";
        exit;
    }
    
    // Test de headers
    echo "<div class='info'><strong>🧪 Test de envío de headers:</strong><br>";
    echo "Tipo detectado: " . ($es_svg ? 'SVG' : 'PDF') . "<br>";
    echo "Content-Type que se enviaría: " . ($es_svg ? 'image/svg+xml' : 'application/pdf') . "</div>";
    
    // Enlaces de prueba
    echo "<div class='info'><strong>🔗 Enlaces de prueba:</strong><br>";
    echo "<a href='descargar.php?participante_id=$participante_id&action=download' target='_blank'>Descargar directo</a><br>";
    echo "<a href='descargar.php?participante_id=$participante_id' target='_blank'>Vista previa</a></div>";
    
    // Mostrar vista previa si es SVG
    if ($es_svg && strlen($contenido) < 50000) { // Solo si es pequeño
        echo "<div class='info'><strong>👁️ Vista previa (SVG):</strong><br>";
        echo "<div style='border:1px solid #ccc; padding:10px; background:white; max-width:100%; overflow:auto;'>";
        echo $contenido;
        echo "</div></div>";
    }
    
    echo "<div class='success'><strong>✅ Diagnóstico completado</strong></div>";
    
} catch (Exception $e) {
    echo "<div class='error'><strong>❌ Error en diagnóstico:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>