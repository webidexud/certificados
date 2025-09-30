<?php
// admin/participantes/editar.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';



$participante_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$error = '';
$success = '';

if (!$participante_id) {
    header('Location: listar.php');
    exit;
}

// Obtener datos del participante
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT p.*, e.nombre as evento_nombre 
        FROM participantes p 
        JOIN eventos e ON p.evento_id = e.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$participante_id]);
    $participante = $stmt->fetch();
    
    if (!$participante) {
        mostrarMensaje('error', 'Participante no encontrado');
        header('Location: listar.php');
        exit;
    }
    
    // Obtener lista de eventos para el select
    $stmt_eventos = $db->query("SELECT id, nombre FROM eventos WHERE estado = 'activo' ORDER BY nombre");
    $eventos = $stmt_eventos->fetchAll();
    
} catch (Exception $e) {
    $error = "Error al cargar el participante: " . $e->getMessage();
}

// Procesar formulario
if ($_POST) {
    $evento_id = intval($_POST['evento_id']);
    $nombres = limpiarDatos($_POST['nombres']);
    $apellidos = limpiarDatos($_POST['apellidos']);
    $numero_identificacion = limpiarDatos($_POST['numero_identificacion']);
    $correo_electronico = limpiarDatos($_POST['correo_electronico']);
    $telefono = limpiarDatos($_POST['telefono']);
    $rol = limpiarDatos($_POST['rol']);
    $institucion = limpiarDatos($_POST['institucion']);
    
    // Validaciones
    if (empty($nombres) || empty($apellidos) || empty($numero_identificacion) || empty($correo_electronico) || empty($rol)) {
        $error = 'Por favor, complete todos los campos obligatorios';
    } elseif (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido';
    } else {
        try {
            // Verificar si el número de identificación ya existe en otro participante del mismo evento
            $stmt_check = $db->prepare("
                SELECT id FROM participantes 
                WHERE evento_id = ? AND numero_identificacion = ? AND id != ?
            ");
            $stmt_check->execute([$evento_id, $numero_identificacion, $participante_id]);
            
            if ($stmt_check->fetch()) {
                $error = 'Ya existe otro participante con ese número de identificación en el evento seleccionado';
            } else {
                $datos_anteriores = $participante;
                
                $stmt = $db->prepare("
                    UPDATE participantes SET 
                    evento_id = ?, nombres = ?, apellidos = ?, numero_identificacion = ?, 
                    correo_electronico = ?, telefono = ?, rol = ?, institucion = ?, 
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $evento_id, $nombres, $apellidos, $numero_identificacion, 
                    $correo_electronico, $telefono, $rol, $institucion, $participante_id
                ]);
                
                $datos_nuevos = [
                    'evento_id' => $evento_id,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'numero_identificacion' => $numero_identificacion,
                    'correo_electronico' => $correo_electronico,
                    'rol' => $rol
                ];
                
                registrarAuditoria('UPDATE', 'participantes', $participante_id, $datos_anteriores, $datos_nuevos);
                
                mostrarMensaje('success', 'Participante actualizado exitosamente');
                header('Location: listar.php');
                exit;
            }
            
        } catch (Exception $e) {
            $error = 'Error al actualizar el participante: ' . $e->getMessage();
        }
    }
} else {
    // Cargar datos del participante en variables
    $evento_id = $participante['evento_id'];
    $nombres = $participante['nombres'];
    $apellidos = $participante['apellidos'];
    $numero_identificacion = $participante['numero_identificacion'];
    $correo_electronico = $participante['correo_electronico'];
    $telefono = $participante['telefono'];
    $rol = $participante['rol'];
    $institucion = $participante['institucion'];
}

// Lista de roles disponibles
$roles_disponibles = ['participante', 'ponente', 'organizador', 'invitado especial', 'moderador'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Participante - Sistema de Certificados</title>
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
            border-bottom: 1px solid #dee2e6;
            padding: 0 20px;
        }
        
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
        }
        
        .nav a {
            display: block;
            padding: 1rem 0;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
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
            color: #495057;
            font-size: 1.8rem;
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
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
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
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #495057;
        }
        
        .required {
            color: #dc3545;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 0.75rem 2rem;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            display: inline-block;
            transition: background 0.3s;
            margin-right: 1rem;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #dee2e6;
        }
        
        .evento-info {
            background: #e9ecef;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #495057;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 0 15px;
            }
            
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
            
            .form-actions {
                flex-direction: column;
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
                <li><a href="../eventos/listar.php">Eventos</a></li>
                <li><a href="listar.php" class="active">Participantes</a></li>
                <li><a href="../certificados/generar.php">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <h2>Editar Participante</h2>
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
            <div class="evento-info">
                <strong>Evento actual:</strong> <?php echo htmlspecialchars($participante['evento_nombre']); ?>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label for="evento_id">Evento <span class="required">*</span></label>
                    <select name="evento_id" id="evento_id" required>
                        <option value="">Seleccione un evento</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>" 
                                    <?php echo ($evento['id'] == $evento_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($evento['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="nombres">Nombres <span class="required">*</span></label>
                        <input type="text" name="nombres" id="nombres" 
                               value="<?php echo htmlspecialchars($nombres); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="apellidos">Apellidos <span class="required">*</span></label>
                        <input type="text" name="apellidos" id="apellidos" 
                               value="<?php echo htmlspecialchars($apellidos); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="numero_identificacion">Número de Identificación <span class="required">*</span></label>
                        <input type="text" name="numero_identificacion" id="numero_identificacion" 
                               value="<?php echo htmlspecialchars($numero_identificacion); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="correo_electronico">Correo Electrónico <span class="required">*</span></label>
                        <input type="email" name="correo_electronico" id="correo_electronico" 
                               value="<?php echo htmlspecialchars($correo_electronico); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input type="tel" name="telefono" id="telefono" 
                               value="<?php echo htmlspecialchars($telefono); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="rol">Rol <span class="required">*</span></label>
                        <select name="rol" id="rol" required>
                            <option value="">Seleccione un rol</option>
                            <?php foreach ($roles_disponibles as $rol_opcion): ?>
                                <option value="<?php echo $rol_opcion; ?>" 
                                        <?php echo ($rol_opcion == $rol) ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($rol_opcion); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="institucion">Institución</label>
                    <input type="text" name="institucion" id="institucion" 
                           value="<?php echo htmlspecialchars($institucion); ?>">
                </div>
                
                <div class="form-actions">
                    <a href="listar.php" class="btn-secondary">Cancelar</a>
                    <button type="submit" class="btn-primary">Actualizar Participante</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>