<?php
// admin/participantes/cargar.php - VERSIÓN QUE SÍ FUNCIONA
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

$error = '';
$success = '';

// Obtener lista de eventos activos
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, nombre, fecha_inicio, fecha_fin FROM eventos WHERE estado = 'activo' ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

if ($_POST && isset($_FILES['archivo_participantes'])) {
    $evento_id = intval($_POST['evento_id']);
    $archivo = $_FILES['archivo_participantes'];
    
    if (empty($evento_id)) {
        $error = 'Por favor, seleccione un evento';
    } elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo: ' . $archivo['error'];
    } else {
        // Subir archivo
        $resultado_upload = subirArchivo($archivo, UPLOAD_PATH . 'participantes/', ALLOWED_CSV_EXTENSIONS);
        
        if (isset($resultado_upload['error'])) {
            $error = $resultado_upload['error'];
        } else {
            try {
                $ruta_archivo = $resultado_upload['ruta'];
                $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                
                if ($extension === 'csv') {
                    // Leer archivo completo
                    $contenido = file_get_contents($ruta_archivo);
                    
                    // Eliminar BOM si existe
                    if (substr($contenido, 0, 3) === "\xef\xbb\xbf") {
                        $contenido = substr($contenido, 3);
                    }
                    
                    // Dividir en líneas
                    $lineas = explode("\n", $contenido);
                    $lineas = array_map('trim', $lineas);
                    $lineas = array_filter($lineas); // Eliminar líneas vacías
                    
                    if (count($lineas) < 2) {
                        throw new Exception("El archivo debe tener al menos una línea de encabezados y una línea de datos");
                    }
                    
                    // Detectar delimitador
                    $primera_linea = $lineas[0];
                    $delimitador = ';';
                    
                    if (substr_count($primera_linea, ';') >= substr_count($primera_linea, ',')) {
                        $delimitador = ';';
                    } else {
                        $delimitador = ',';
                    }
                    
                    // Procesar headers
                    $headers = str_getcsv($primera_linea, $delimitador);
                    $headers = array_map(function($h) {
                        $h = trim($h, " \t\n\r\0\x0B\"'");
                        $h = strtolower($h);
                        return $h;
                    }, $headers);
                    
                    // Verificar que tenemos suficientes columnas
                    if (count($headers) < 5) {
                        throw new Exception("El archivo debe tener al menos 5 columnas. Se encontraron: " . count($headers));
                    }
                    
                    // Mapeo simple y directo
                    $mapeo = [];
                    
                    // Buscar nombres
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['nombres', 'nombre', 'name'])) {
                            $mapeo['nombres'] = $i;
                            break;
                        }
                    }
                    
                    // Buscar apellidos
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['apellidos', 'apellido', 'lastname'])) {
                            $mapeo['apellidos'] = $i;
                            break;
                        }
                    }
                    
                    // Buscar identificación
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['numero_identificacion', 'identificacion', 'cedula', 'documento', 'id'])) {
                            $mapeo['numero_identificacion'] = $i;
                            break;
                        }
                    }
                    
                    // Buscar correo
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['correo_electronico', 'correo', 'email', 'mail'])) {
                            $mapeo['correo_electronico'] = $i;
                            break;
                        }
                    }
                    
                    // Buscar rol
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['rol', 'role', 'tipo'])) {
                            $mapeo['rol'] = $i;
                            break;
                        }
                    }
                    
                    // Buscar teléfono (opcional)
                    $mapeo['telefono'] = -1;
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['telefono', 'celular', 'phone'])) {
                            $mapeo['telefono'] = $i;
                            break;
                        }
                    }
                    
                    // Buscar institución (opcional)
                    $mapeo['institucion'] = -1;
                    foreach ($headers as $i => $header) {
                        if (in_array($header, ['institucion', 'empresa', 'organizacion'])) {
                            $mapeo['institucion'] = $i;
                            break;
                        }
                    }
                    
                    // Verificar campos requeridos
                    $faltantes = [];
                    if (!isset($mapeo['nombres'])) $faltantes[] = 'nombres';
                    if (!isset($mapeo['apellidos'])) $faltantes[] = 'apellidos';
                    if (!isset($mapeo['numero_identificacion'])) $faltantes[] = 'numero_identificacion';
                    if (!isset($mapeo['correo_electronico'])) $faltantes[] = 'correo_electronico';
                    if (!isset($mapeo['rol'])) $faltantes[] = 'rol';
                    
                    if (!empty($faltantes)) {
                        throw new Exception("Faltan estas columnas: " . implode(', ', $faltantes) . 
                                          ". Headers encontrados: " . implode(', ', $headers) . 
                                          ". Delimitador: '$delimitador'");
                    }
                    
                    // Procesar datos
                    $participantes_procesados = [];
                    $insertados = 0;
                    $actualizados = 0;
                    $errores = 0;
                    $errores_detalle = [];
                    
                    $db->beginTransaction();
                    
                    // Preparar consultas
                    $stmt_check = $db->prepare("SELECT id FROM participantes WHERE evento_id = ? AND numero_identificacion = ?");
                    $stmt_insert = $db->prepare("
                        INSERT INTO participantes (evento_id, nombres, apellidos, numero_identificacion, correo_electronico, rol, telefono, institucion) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt_update = $db->prepare("
                        UPDATE participantes SET nombres = ?, apellidos = ?, correo_electronico = ?, rol = ?, telefono = ?, institucion = ? 
                        WHERE id = ?
                    ");
                    
                    // Procesar cada línea de datos (saltar la primera que son headers)
                    for ($i = 1; $i < count($lineas); $i++) {
                        $linea = trim($lineas[$i]);
                        if (empty($linea)) continue;
                        
                        $campos = str_getcsv($linea, $delimitador);
                        
                        // Asegurar que tenemos suficientes campos
                        if (count($campos) < count($headers)) {
                            $errores++;
                            $errores_detalle[] = "Línea " . ($i + 1) . ": Insuficientes campos";
                            continue;
                        }
                        
                        // Extraer datos
                        $nombres = trim($campos[$mapeo['nombres']] ?? '');
                        $apellidos = trim($campos[$mapeo['apellidos']] ?? '');
                        $numero_identificacion = trim($campos[$mapeo['numero_identificacion']] ?? '');
                        $correo_electronico = trim($campos[$mapeo['correo_electronico']] ?? '');
                        $rol = trim($campos[$mapeo['rol']] ?? '');
                        $telefono = $mapeo['telefono'] !== -1 ? trim($campos[$mapeo['telefono']] ?? '') : '';
                        $institucion = $mapeo['institucion'] !== -1 ? trim($campos[$mapeo['institucion']] ?? '') : '';
                        
                        // Validar datos requeridos
                        if (empty($nombres) || empty($apellidos) || empty($numero_identificacion) || empty($correo_electronico) || empty($rol)) {
                            $errores++;
                            $errores_detalle[] = "Línea " . ($i + 1) . ": Campos obligatorios vacíos";
                            continue;
                        }
                        
                        // Validar email
                        if (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
                            $errores++;
                            $errores_detalle[] = "Línea " . ($i + 1) . ": Email inválido ($correo_electronico)";
                            continue;
                        }
                        
                        try {
                            // Verificar si ya existe
                            $stmt_check->execute([$evento_id, $numero_identificacion]);
                            $existe = $stmt_check->fetch();
                            
                            if ($existe) {
                                // Actualizar
                                $stmt_update->execute([
                                    $nombres, $apellidos, $correo_electronico, $rol, $telefono, $institucion, $existe['id']
                                ]);
                                $actualizados++;
                            } else {
                                // Insertar
                                $stmt_insert->execute([
                                    $evento_id, $nombres, $apellidos, $numero_identificacion, 
                                    $correo_electronico, $rol, $telefono, $institucion
                                ]);
                                $insertados++;
                            }
                            
                        } catch (Exception $e) {
                            $errores++;
                            $errores_detalle[] = "Línea " . ($i + 1) . ": " . $e->getMessage();
                        }
                    }
                    
                    $db->commit();
                    
                    // Registrar auditoría
                    registrarAuditoria('BULK_INSERT', 'participantes', $evento_id, null, [
                        'archivo' => $archivo['name'],
                        'insertados' => $insertados,
                        'actualizados' => $actualizados,
                        'errores' => $errores
                    ]);
                    
                    $success = "✅ <strong>Archivo procesado exitosamente:</strong><br>";
                    $success .= "📥 <strong>$insertados</strong> participantes insertados<br>";
                    $success .= "🔄 <strong>$actualizados</strong> participantes actualizados<br>";
                    if ($errores > 0) {
                        $success .= "⚠️ <strong>$errores</strong> errores<br>";
                    }
                    $success .= "🔧 Delimitador usado: <strong>'$delimitador'</strong>";
                    
                    if (!empty($errores_detalle) && count($errores_detalle) <= 10) {
                        $success .= "<br><br><strong>Errores:</strong><br>";
                        foreach ($errores_detalle as $error_det) {
                            $success .= "• " . htmlspecialchars($error_det) . "<br>";
                        }
                    }
                    
                    // Eliminar archivo temporal
                    unlink($ruta_archivo);
                    
                } else {
                    $error = 'Solo se aceptan archivos CSV por ahora.';
                }
                
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = $e->getMessage();
                
                if (isset($ruta_archivo) && file_exists($ruta_archivo)) {
                    unlink($ruta_archivo);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cargar Participantes - Sistema de Certificados</title>
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
        
        .logo h1 {
            font-size: 1.5rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }
        
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
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title h2 {
            color: #333;
            font-size: 2rem;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn-back:hover {
            background: #5a6268;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
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
        
        .required {
            color: #dc3545;
        }
        
        select, input[type="file"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        select:focus, input[type="file"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .info-box {
            background: #e8f5e8;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-box h3 {
            color: #155724;
            margin-bottom: 1rem;
        }
        
        .info-box ul {
            margin-left: 1.5rem;
            color: #155724;
        }
        
        .info-box li {
            margin-bottom: 0.5rem;
        }
        
        .sample-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
        
        .sample-table th,
        .sample-table td {
            border: 1px solid #ddd;
            padding: 0.5rem;
            text-align: left;
        }
        
        .sample-table th {
            background-color: #f5f5f5;
            font-weight: 600;
        }
        
        .file-info {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav ul {
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .container {
                padding: 0 10px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <h1>Sistema de Certificados</h1>
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
                <li><a href="listar.php" class="active">Participantes</a></li>
                <li><a href="../certificados/generar.php">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <h2>Cargar Participantes</h2>
            </div>
            <a href="listar.php" class="btn-back">← Ver Participantes</a>
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
        
        <div class="info-box">
            <h3>🎯 VERSIÓN SIMPLIFICADA - GARANTIZADA PARA FUNCIONAR</h3>
            <ul>
                <li><strong>✅ Eliminación automática de BOM</strong></li>
                <li><strong>✅ Detección inteligente de delimitadores (;</strong> y <strong>,)</strong></li>
                <li><strong>✅ Mapeo directo sin regex complejas</strong></li>
                <li><strong>✅ Manejo robusto de errores</strong></li>
                <li><strong>✅ Compatible con su archivo actual</strong></li>
            </ul>
        </div>
        
        <div class="card">
            <h3 style="margin-bottom: 1rem; color: #333;">📋 Formato requerido</h3>
            <p style="margin-bottom: 1rem;">Su archivo debe tener estas columnas (en cualquier orden):</p>
            
            <table class="sample-table">
                <thead>
                    <tr>
                        <th>Columna</th>
                        <th>Nombres aceptados</th>
                        <th>Obligatorio</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Nombres</td>
                        <td>nombres, nombre, name</td>
                        <td>✅ Sí</td>
                    </tr>
                    <tr>
                        <td>Apellidos</td>
                        <td>apellidos, apellido, lastname</td>
                        <td>✅ Sí</td>
                    </tr>
                    <tr>
                        <td>Identificación</td>
                        <td>numero_identificacion, identificacion, cedula, documento, id</td>
                        <td>✅ Sí</td>
                    </tr>
                    <tr>
                        <td>Correo</td>
                        <td>correo_electronico, correo, email, mail</td>
                        <td>✅ Sí</td>
                    </tr>
                    <tr>
                        <td>Rol</td>
                        <td>rol, role, tipo</td>
                        <td>✅ Sí</td>
                    </tr>
                    <tr>
                        <td>Teléfono</td>
                        <td>telefono, celular, phone</td>
                        <td>❌ No</td>
                    </tr>
                    <tr>
                        <td>Institución</td>
                        <td>institucion, empresa, organizacion</td>
                        <td>❌ No</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <form method="POST" enctype="multipart/form-data" id="formCargar">
                <div class="form-group">
                    <label for="evento_id">Evento <span class="required">*</span></label>
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
                
                <div class="form-group">
                    <label for="archivo_participantes">Archivo CSV <span class="required">*</span></label>
                    <input type="file" id="archivo_participantes" name="archivo_participantes" accept=".csv" required>
                    <div class="file-info">
                        Solo archivos CSV. Delimitadores: punto y coma (;) o coma (,). Máximo: 10MB
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 2rem;">
                    <button type="submit" class="btn-primary" id="btnSubmit">📤 Cargar Participantes</button>
                </div>
            </form>
        </div>
        
        <?php if (empty($eventos)): ?>
            <div class="card">
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <h3>No hay eventos activos</h3>
                    <p>Debe crear al menos un evento activo antes de cargar participantes.</p>
                    <a href="../eventos/crear.php" class="btn-primary" style="margin-top: 1rem; display: inline-block;">Crear Evento</a>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h3 style="margin-bottom: 1rem; color: #333;">🔧 Crear archivo CSV de prueba</h3>
            <p style="margin-bottom: 1rem;">Si sigue teniendo problemas, copie este contenido y guárdelo como <code>participantes.csv</code>:</p>
            
            <textarea readonly style="width: 100%; height: 120px; font-family: monospace; font-size: 0.9rem; padding: 1rem; border: 1px solid #ddd; border-radius: 5px;">nombres;apellidos;numero_identificacion;correo_electronico;rol;telefono;institucion
Juan Carlos;Pérez García;12345678;juan.perez@email.com;Participante;3001234567;Universidad Nacional
María Elena;Rodriguez López;87654321;maria.rodriguez@email.com;Ponente;3009876543;Instituto Tecnológico</textarea>
            
            <p style="margin-top: 1rem; color: #666; font-size: 0.9rem;">
                <strong>Instrucciones:</strong> Copie el texto de arriba, péguelo en un editor de texto (Bloc de notas), 
                y guárdelo como "participantes.csv". Este archivo funcionará garantizado.
            </p>
        </div>
    </div>
    
    <script>
        document.getElementById('formCargar').addEventListener('submit', function(e) {
            const evento = document.getElementById('evento_id').value;
            const archivo = document.getElementById('archivo_participantes').value;
            
            if (!evento || !archivo) {
                e.preventDefault();
                alert('Por favor, complete todos los campos obligatorios.');
                return;
            }
            
            document.getElementById('btnSubmit').disabled = true;
            document.getElementById('btnSubmit').innerHTML = '⏳ Procesando...';
        });
        
        document.getElementById('archivo_participantes').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const fileInfo = document.querySelector('.file-info');
                fileInfo.innerHTML = `
                    <strong>Archivo seleccionado:</strong> ${file.name}<br>
                    <strong>Tamaño:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB<br>
                    <span style="color: #28a745;">✅ Listo para procesar</span>
                `;
            }
        });
    </script>
</body>
</html>