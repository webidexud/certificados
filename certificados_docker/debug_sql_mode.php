<?php
// Archivo temporal para debuggear el modo SQL
// Guardar como: debug_sql_mode.php en la raíz del proyecto

require_once 'config/config.php';
require_once 'includes/funciones.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Verificar el modo SQL actual
    $stmt = $db->query("SELECT @@sql_mode");
    $sql_mode = $stmt->fetchColumn();
    
    echo "<h3>Modo SQL actual:</h3>";
    echo "<pre>" . htmlspecialchars($sql_mode) . "</pre>";
    
    // Verificar si ONLY_FULL_GROUP_BY está activado
    if (strpos($sql_mode, 'ONLY_FULL_GROUP_BY') !== false) {
        echo "<p style='color: red;'><strong>❌ ONLY_FULL_GROUP_BY está ACTIVADO</strong></p>";
        
        // Mostrar comando para desactivarlo
        echo "<h4>Para desactivarlo temporalmente, ejecuta:</h4>";
        echo "<pre>SET sql_mode = (SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY,',''));</pre>";
        
        // Intentar desactivarlo automáticamente
        try {
            $db->exec("SET sql_mode = (SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY,',''))");
            echo "<p style='color: green;'><strong>✅ ONLY_FULL_GROUP_BY desactivado temporalmente</strong></p>";
            
            // Verificar nuevamente
            $stmt = $db->query("SELECT @@sql_mode");
            $new_sql_mode = $stmt->fetchColumn();
            echo "<h4>Nuevo modo SQL:</h4>";
            echo "<pre>" . htmlspecialchars($new_sql_mode) . "</pre>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error al desactivar: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: green;'><strong>✅ ONLY_FULL_GROUP_BY está DESACTIVADO</strong></p>";
    }
    
    // Probar la consulta problemática
    echo "<h3>Probando la consulta del evento:</h3>";
    
    $evento_id = 1; // Cambia este ID según tu caso
    
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.evento_id,
            p.nombres, 
            p.apellidos,
            p.numero_identificacion,
            p.correo_electronico,
            p.rol,
            p.telefono,
            p.institucion,
            p.created_at,
            p.updated_at,
            c.id as certificado_id,
            c.codigo_verificacion,
            c.fecha_generacion,
            (SELECT COUNT(DISTINCT pt.id) 
            FROM plantillas_certificados pt 
            WHERE pt.evento_id = p.evento_id 
            AND (pt.rol = p.rol OR pt.rol = 'General')
            ) as plantillas_disponibles
        FROM participantes p
        LEFT JOIN certificados c ON p.id = c.participante_id
        WHERE p.evento_id = ?
        ORDER BY p.apellidos, p.nombres
    ");
    
    $stmt->execute([$evento_id]);
    $result = $stmt->fetchAll();
    
    echo "<p style='color: green;'><strong>✅ Consulta ejecutada exitosamente</strong></p>";
    echo "<p>Participantes encontrados: " . count($result) . "</p>";
    
    if (count($result) > 0) {
        echo "<h4>Primer participante:</h4>";
        echo "<pre>" . print_r($result[0], true) . "</pre>";
    }
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ Error:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
h3, h4 { margin-top: 20px; }
</style>