<?php
// includes/funciones_svg.php - Funciones mejoradas para manejo de SVG

/**
 * Procesa texto largo en SVG dividiéndolo en múltiples líneas
 */
function procesarTextoSVG($contenido_svg, $variables_datos) {
    foreach ($variables_datos as $variable => $valor) {
        // Buscar elementos de texto que contengan esta variable
        $patron = '/<text([^>]*?)>(.*?)' . preg_quote($variable, '/') . '(.*?)<\/text>/s';
        
        if (preg_match_all($patron, $contenido_svg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $elemento_completo = $match[0];
                $atributos = $match[1];
                $texto_antes = $match[2];
                $texto_despues = $match[3];
                
                // Extraer atributos del elemento text
                $x = extraerAtributo($atributos, 'x', '0');
                $y = extraerAtributo($atributos, 'y', '0');
                $font_size = extraerAtributo($atributos, 'font-size', '16');
                $text_anchor = extraerAtributo($atributos, 'text-anchor', 'start');
                
                // Determinar si necesita división de líneas
                $texto_completo = $texto_antes . $valor . $texto_despues;
                $lineas = dividirTextoEnLineas($texto_completo, intval($font_size));
                
                if (count($lineas) > 1) {
                    // Crear elemento text con múltiples tspan
                    $nuevo_elemento = crearTextoMultilinea($atributos, $x, $y, $lineas, $font_size);
                    $contenido_svg = str_replace($elemento_completo, $nuevo_elemento, $contenido_svg);
                } else {
                    // Reemplazo simple
                    $contenido_svg = str_replace($variable, htmlspecialchars($valor, ENT_XML1), $contenido_svg);
                }
            }
        } else {
            // Reemplazo simple si no está en un elemento text
            $contenido_svg = str_replace($variable, htmlspecialchars($valor, ENT_XML1), $contenido_svg);
        }
    }
    
    return $contenido_svg;
}

/**
 * Divide texto largo en múltiples líneas basado en el tamaño de fuente
 */
function dividirTextoEnLineas($texto, $font_size) {
    // Calcular caracteres máximos por línea basado en el tamaño de fuente
    $caracteres_por_linea = max(20, 60 - ($font_size / 2));
    
    $palabras = explode(' ', $texto);
    $lineas = [];
    $linea_actual = '';
    
    foreach ($palabras as $palabra) {
        $linea_test = empty($linea_actual) ? $palabra : $linea_actual . ' ' . $palabra;
        
        if (strlen($linea_test) <= $caracteres_por_linea) {
            $linea_actual = $linea_test;
        } else {
            if (!empty($linea_actual)) {
                $lineas[] = $linea_actual;
                $linea_actual = $palabra;
            } else {
                // Palabra muy larga, dividirla
                $lineas[] = substr($palabra, 0, $caracteres_por_linea);
                $linea_actual = substr($palabra, $caracteres_por_linea);
            }
        }
    }
    
    if (!empty($linea_actual)) {
        $lineas[] = $linea_actual;
    }
    
    return $lineas;
}

/**
 * Crea elemento text con múltiples líneas usando tspan
 */
function crearTextoMultilinea($atributos_originales, $x, $y, $lineas, $font_size) {
    $interlineado = $font_size * 1.2; // 120% del tamaño de fuente
    $y_inicial = $y - (count($lineas) - 1) * $interlineado / 2; // Centrar verticalmente
    
    $elemento = "<text{$atributos_originales}>\n";
    
    foreach ($lineas as $index => $linea) {
        $y_linea = $y_inicial + ($index * $interlineado);
        $elemento .= "    <tspan x=\"{$x}\" y=\"{$y_linea}\">" . htmlspecialchars(trim($linea), ENT_XML1) . "</tspan>\n";
    }
    
    $elemento .= "</text>";
    
    return $elemento;
}

/**
 * Extrae valor de atributo de una cadena de atributos SVG
 */
function extraerAtributo($atributos, $nombre, $default = '') {
    $patron = '/' . $nombre . '=["\']([^"\']*)["\']/' ;
    if (preg_match($patron, $atributos, $matches)) {
        return $matches[1];
    }
    return $default;
}

/**
 * Optimiza SVG para mejor renderizado de texto
 */
function optimizarSVGTexto($contenido_svg) {
    // Añadir atributos para mejor renderizado de texto
    $contenido_svg = str_replace('<svg', '<svg text-rendering="geometricPrecision" shape-rendering="geometricPrecision"', $contenido_svg);
    
    // Asegurar que todos los elementos text tengan text-anchor si no lo tienen
    $contenido_svg = preg_replace_callback('/<text(?![^>]*text-anchor)([^>]*?)>/', function($matches) {
        return '<text text-anchor="middle"' . $matches[1] . '>';
    }, $contenido_svg);
    
    return $contenido_svg;
}

/**
 * Procesa nombres largos específicamente
 */
function procesarNombresLargos($contenido_svg, $nombres, $apellidos) {
    $nombre_completo = trim($nombres . ' ' . $apellidos);
    
    // Si el nombre es muy largo (más de 30 caracteres), dividirlo
    if (strlen($nombre_completo) > 30) {
        // Buscar el elemento text que contiene {{nombres}} {{apellidos}}
        $patron = '/<text([^>]*?)>([^<]*)\{\{nombres\}\}([^<]*)\{\{apellidos\}\}([^<]*)<\/text>/s';
        
        if (preg_match($patron, $contenido_svg, $matches)) {
            $atributos = $matches[1];
            $antes_nombres = $matches[2];
            $entre_nombres = $matches[3];
            $despues_apellidos = $matches[4];
            
            $x = extraerAtributo($atributos, 'x', '421');
            $y = extraerAtributo($atributos, 'y', '315');
            $font_size = extraerAtributo($atributos, 'font-size', '32');
            
            // Crear versión multilínea
            $lineas = [];
            if (strlen($nombres) > 20) {
                $lineas[] = $antes_nombres . $nombres;
                $lineas[] = $apellidos . $despues_apellidos;
            } else {
                $lineas[] = $antes_nombres . $nombre_completo . $despues_apellidos;
            }
            
            $nuevo_elemento = crearTextoMultilinea($atributos, $x, $y, $lineas, intval($font_size));
            $contenido_svg = str_replace($matches[0], $nuevo_elemento, $contenido_svg);
        }
    } else {
        // Reemplazo normal
        $contenido_svg = str_replace('{{nombres}}', htmlspecialchars($nombres, ENT_XML1), $contenido_svg);
        $contenido_svg = str_replace('{{apellidos}}', htmlspecialchars($apellidos, ENT_XML1), $contenido_svg);
    }
    
    return $contenido_svg;
}

/**
 * Procesa nombres de eventos largos
 */
function procesarEventosLargos($contenido_svg, $evento_nombre) {
    if (strlen($evento_nombre) > 50) {
        $patron = '/<text([^>]*?)>([^<]*)\{\{evento_nombre\}\}([^<]*)<\/text>/s';
        
        if (preg_match($patron, $contenido_svg, $matches)) {
            $atributos = $matches[1];
            $antes = $matches[2];
            $despues = $matches[3];
            
            $x = extraerAtributo($atributos, 'x', '421');
            $y = extraerAtributo($atributos, 'y', '400');
            $font_size = extraerAtributo($atributos, 'font-size', '24');
            
            $lineas = dividirTextoEnLineas($antes . $evento_nombre . $despues, intval($font_size));
            $nuevo_elemento = crearTextoMultilinea($atributos, $x, $y, $lineas, intval($font_size));
            $contenido_svg = str_replace($matches[0], $nuevo_elemento, $contenido_svg);
        }
    } else {
        $contenido_svg = str_replace('{{evento_nombre}}', htmlspecialchars($evento_nombre, ENT_XML1), $contenido_svg);
    }
    
    return $contenido_svg;
}
?>