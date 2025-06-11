<?php
// includes/funciones_svg.php - Funciones mejoradas para manejo de SVG

/**
 * Procesa texto largo en SVG dividiéndolo en múltiples líneas
 */
function procesarTextoSVG($contenido_svg, $variables_datos) {
    // Reemplazo simple y directo de variables
    foreach ($variables_datos as $variable => $valor) {
        $contenido_svg = str_replace($variable, htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $contenido_svg);
    }
    
    return $contenido_svg;
}

/**
 * Procesa nombres largos específicamente
 */
function procesarNombresLargos($contenido_svg, $nombres, $apellidos) {
    $nombre_completo = trim($nombres . ' ' . $apellidos);
    
    // Si el nombre es muy largo (más de 35 caracteres), intentar dividirlo
    if (strlen($nombre_completo) > 35) {
        // Buscar elementos text que contengan el nombre completo
        $patron = '/<text([^>]*?)>([^<]*{{nombres}}[^<]*{{apellidos}}[^<]*)<\/text>/';
        
        if (preg_match($patron, $contenido_svg, $matches)) {
            $atributos = $matches[1];
            $texto_contenido = $matches[2];
            
            // Reemplazar con nombre dividido
            $texto_dividido = str_replace('{{nombres}} {{apellidos}}', $nombres . "\n" . $apellidos, $texto_contenido);
            $texto_dividido = str_replace('{{nombres}}', $nombres, $texto_dividido);
            $texto_dividido = str_replace('{{apellidos}}', $apellidos, $texto_dividido);
            
            $nuevo_elemento = "<text{$atributos}>" . htmlspecialchars($texto_dividido, ENT_XML1) . "</text>";
            $contenido_svg = str_replace($matches[0], $nuevo_elemento, $contenido_svg);
        }
    }
    
    return $contenido_svg;
}

/**
 * Procesa nombres de eventos largos
 */
function procesarEventosLargos($contenido_svg, $evento_nombre) {
    // Si el evento es muy largo (más de 60 caracteres), no procesamos nada especial
    // El SVG debe estar diseñado para manejar texto largo
    return $contenido_svg;
}

/**
 * Optimiza SVG para mejor renderizado de texto
 */
function optimizarSVGTexto($contenido_svg) {
    // Añadir atributos para mejor renderizado de texto si no existen
    if (strpos($contenido_svg, 'text-rendering') === false) {
        $contenido_svg = str_replace('<svg', '<svg text-rendering="geometricPrecision" shape-rendering="geometricPrecision"', $contenido_svg);
    }
    
    // Asegurar codificación UTF-8
    if (strpos($contenido_svg, '<?xml') === false) {
        $contenido_svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $contenido_svg;
    }
    
    return $contenido_svg;
}

/**
 * Valida que el SVG tenga las variables mínimas requeridas
 */
function validarPlantillaSVG($contenido_svg) {
    $variables_requeridas = [
        '{{nombres}}',
        '{{apellidos}}', 
        '{{evento_nombre}}',
        '{{codigo_verificacion}}'
    ];
    
    $variables_faltantes = [];
    foreach ($variables_requeridas as $variable) {
        if (strpos($contenido_svg, $variable) === false) {
            $variables_faltantes[] = $variable;
        }
    }
    
    return [
        'valido' => empty($variables_faltantes),
        'variables_faltantes' => $variables_faltantes
    ];
}

/**
 * Lista todas las variables encontradas en un SVG
 */
function extraerVariablesSVG($contenido_svg) {
    preg_match_all('/\{\{([^}]+)\}\}/', $contenido_svg, $matches);
    return array_unique($matches[0]);
}

/**
 * Limpia el SVG de contenido peligroso
 */
function limpiarSVG($contenido_svg) {
    // Eliminar scripts
    $contenido_svg = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $contenido_svg);
    
    // Eliminar eventos JavaScript
    $contenido_svg = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $contenido_svg);
    
    // Eliminar elementos peligrosos
    $elementos_peligrosos = ['script', 'object', 'embed', 'iframe', 'form'];
    foreach ($elementos_peligrosos as $elemento) {
        $contenido_svg = preg_replace("/<{$elemento}[^>]*>.*?<\/{$elemento}>/is", '', $contenido_svg);
    }
    
    return $contenido_svg;
}
?>