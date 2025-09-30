<?php
// admin/eventos/editar.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';



$evento_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$error = '';
$success = '';

if (!$evento_id) {
    header('Location: listar.php');
    exit;
}

// Obtener datos del evento
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
    $stmt->execute([$evento_id]);
    $evento = $stmt->fetch();
    
    if (!$evento) {
        mostrarMensaje('error', 'Evento no encontrado');
        header('Location: listar.php');
        exit;
    }
    
} catch (Exception $e) {
    $error = "Error al cargar el evento: " . $e->getMessage();
}

// Procesar formulario
if ($_POST) {
    $nombre = limpiarDatos($_POST['nombre']);
    $descripcion = limpiarDatos($_POST['descripcion']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];
    $modalidad = $_POST['modalidad'];
    $entidad_organizadora = limpiarDatos($_POST['entidad_organizadora']);
    $lugar = limpiarDatos($_POST['lugar']);
    $horas_duracion = intval($_POST['horas_duracion']);
    $estado = $_POST['estado'];
    
    // Validaciones
    if (empty($nombre) || empty($fecha_inicio) || empty($fecha_fin) || empty($modalidad) || empty($entidad_organizadora)) {
        $error = 'Por favor, complete todos los campos obligatorios';
    } elseif (!validarFecha($fecha_inicio) || !validarFecha($fecha_fin)) {
        $error = 'Las fechas no son válidas';
    } elseif ($fecha_inicio > $fecha_fin) {
        $error = 'La fecha de fin debe ser posterior a la fecha de inicio';
    } else {
        try {
            $datos_anteriores = $evento;
            
            $stmt = $db->prepare("
                UPDATE eventos SET 
                nombre = ?, descripcion = ?, fecha_inicio = ?, fecha_fin = ?, 
                modalidad = ?, entidad_organizadora = ?, lugar = ?, 
                horas_duracion = ?, estado = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([
                $nombre, $descripcion, $fecha_inicio, $fecha_fin, 
                $modalidad, $entidad_organizadora, $lugar, 
                $horas_duracion, $estado, $evento_id
            ]);
            
            $datos_nuevos = [
                'nombre' => $nombre,
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin,
                'modalidad' => $modalidad,
                'estado' => $estado
            ];
            
            registrarAuditoria('UPDATE', 'eventos', $evento_id, $datos_anteriores, $datos_nuevos);
            
            mostrarMensaje('success', 'Evento actualizado exitosamente');
            header('Location: listar.php');
            exit;
            
        } catch (Exception $e) {
            $error = 'Error al actualizar el evento: ' . $e->getMessage();
        }
    }
} else {
    // Cargar datos del evento en variables
    $nombre = $evento['nombre'];
    $descripcion = $evento['descripcion'];
    $fecha_inicio = $evento['fecha_inicio'];
    $fecha_fin = $evento['fecha_fin'];
    $modalidad = $evento['modalidad'];
    $entidad_organizadora = $evento['entidad_organizadora'];
    $lugar = $evento['lugar'];
    $horas_duracion = $evento['horas_duracion'];
    $estado = $evento['estado'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Evento - Sistema de Certificados</title>
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
        }
        
        .form-grid {
            display: grid;
            gap: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        .required {
            color: #dc3545;
        }
        
        input, select, textarea {
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
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
            text-align: center;
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
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav ul {
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
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
                <a href="../logout.php" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="../index.php">Dashboard</a></li>
                <li><a href="listar.php" class="active">Eventos</a></li>
                <li><a href="../participantes/listar.php">Participantes</a></li>
                <li><a href="../certificados/generar.php">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <h2>Editar Evento</h2>
            </div>
            <a href="listar.php" class="btn-back">← Volver a la lista</a>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nombre">Nombre del Evento <span class="required">*</span></label>
                        <input type="text" id="nombre" name="nombre" value="<?php echo htmlspecialchars($nombre); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="descripcion">Descripción</label>
                        <textarea id="descripcion" name="descripcion" placeholder="Descripción detallada del evento..."><?php echo htmlspecialchars($descripcion); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="fecha_inicio">Fecha de Inicio <span class="required">*</span></label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="fecha_fin">Fecha de Fin <span class="required">*</span></label>
                            <input type="date" id="fecha_fin" name="fecha_fin" value="<?php echo $fecha_fin; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modalidad">Modalidad <span class="required">*</span></label>
                            <select id="modalidad" name="modalidad" required>
                                <option value="">Seleccione una modalidad</option>
                                <option value="presencial" <?php echo $modalidad == 'presencial' ? 'selected' : ''; ?>>Presencial</option>
                                <option value="virtual" <?php echo $modalidad == 'virtual' ? 'selected' : ''; ?>>Virtual</option>
                                <option value="hibrida" <?php echo $modalidad == 'hibrida' ? 'selected' : ''; ?>>Híbrida</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="horas_duracion">Horas de Duración</label>
                            <input type="number" id="horas_duracion" name="horas_duracion" min="1" max="2000" value="<?php echo $horas_duracion; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="entidad_organizadora">Entidad Organizadora <span class="required">*</span></label>
                        <input type="text" id="entidad_organizadora" name="entidad_organizadora" value="<?php echo htmlspecialchars($entidad_organizadora); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="lugar">Lugar</label>
                        <input type="text" id="lugar" name="lugar" value="<?php echo htmlspecialchars($lugar); ?>" placeholder="Dirección, plataforma virtual, etc.">
                    </div>
                    
                    <div class="form-group">
                        <label for="estado">Estado</label>
                        <select id="estado" name="estado" required>
                            <option value="activo" <?php echo $estado == 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo $estado == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                    </div>
                    
                    <div style="text-align: center; margin-top: 2rem;">
                        <button type="submit" class="btn-primary">Actualizar Evento</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Validación del lado del cliente
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            const fechaInicio = this.value;
            const fechaFin = document.getElementById('fecha_fin');
            
            if (fechaInicio) {
                fechaFin.min = fechaInicio;
                if (fechaFin.value && fechaFin.value < fechaInicio) {
                    fechaFin.value = fechaInicio;
                }
            }
        });
    </script>
</body>
</html>
