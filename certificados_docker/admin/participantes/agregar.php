<?php
// admin/participantes/agregar.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';


$error = '';
$success = '';

// Obtener lista de eventos activos para el select
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, nombre FROM eventos WHERE estado = 'activo' ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
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
    if (empty($nombres) || empty($apellidos) || empty($numero_identificacion) || empty($correo_electronico) || empty($rol) || empty($evento_id)) {
        $error = 'Por favor, complete todos los campos obligatorios';
    } elseif (!filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido';
    } else {
        try {
            // Verificar si el número de identificación ya existe en el mismo evento
            $stmt_check = $db->prepare("
                SELECT id FROM participantes 
                WHERE evento_id = ? AND numero_identificacion = ?
            ");
            $stmt_check->execute([$evento_id, $numero_identificacion]);
            $participante_existente = $stmt_check->fetch();
            
            if ($participante_existente) {
                $error = 'Ya existe un participante con ese número de identificación en este evento';
            } else {
                // Insertar nuevo participante
                $stmt = $db->prepare("
                    INSERT INTO participantes (evento_id, nombres, apellidos, numero_identificacion, correo_electronico, rol, telefono, institucion) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $evento_id, $nombres, $apellidos, $numero_identificacion, 
                    $correo_electronico, $rol, $telefono, $institucion
                ]);
                
                $participante_id = $db->lastInsertId();
                
                // Registrar en auditoría
                registrarAuditoria('INSERT', 'participantes', $participante_id, null, [
                    'evento_id' => $evento_id,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'numero_identificacion' => $numero_identificacion,
                    'correo_electronico' => $correo_electronico,
                    'rol' => $rol,
                    'telefono' => $telefono,
                    'institucion' => $institucion
                ]);
                
                $_SESSION['success_mensaje'] = 'Participante agregado exitosamente: ' . $nombres . ' ' . $apellidos;
                header('Location: listar.php');
                exit;
            }
            
        } catch (Exception $e) {
            $error = "Error al guardar el participante: " . $e->getMessage();
        }
    }
} else {
    // Inicializar variables para el formulario
    $evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : '';
    $nombres = '';
    $apellidos = '';
    $numero_identificacion = '';
    $correo_electronico = '';
    $telefono = '';
    $rol = '';
    $institucion = '';
}

// Lista de roles disponibles (misma lógica que editar.php)
$roles_disponibles = ['participante', 'ponente', 'organizador', 'invitado especial', 'moderador'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar Participante - Sistema de Certificados</title>
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
            transition: background-color 0.3s;
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .main-content {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 20px;
        }
        
        .breadcrumb {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-header {
            background: #f8f9fa;
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
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
            flex: 1;
        }
        
        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-row .form-group {
            margin-bottom: 0;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        
        .required {
            color: #dc3545;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.25);
        }
        
        select {
            background-color: white;
            cursor: pointer;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
            margin-top: 2rem;
        }
        
        .help-text {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <h1>📜 Sistema de Certificados</h1>
            </div>
            <div class="user-info">
                <a href="../logout.php" class="btn-logout">🚪 Salir</a>
            </div>
        </div>
    </header>

    <!-- Contenido principal -->
    <div class="main-content">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../index.php">🏠 Dashboard</a> / 
            <a href="listar.php">👥 Participantes</a> / 
            <strong>➕ Agregar Participante</strong>
        </div>

        <!-- Formulario -->
        <div class="card">
            <div class="card-header">
                ➕ Agregar Nuevo Participante
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <strong>✅ Éxito:</strong> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <!-- Evento -->
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
                        <div class="help-text">Seleccione el evento al que pertenecerá el participante</div>
                    </div>
                    
                    <!-- Nombres y Apellidos -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nombres">Nombres <span class="required">*</span></label>
                            <input type="text" name="nombres" id="nombres" 
                                   value="<?php echo htmlspecialchars($nombres); ?>" 
                                   placeholder="Ej: Juan Carlos" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="apellidos">Apellidos <span class="required">*</span></label>
                            <input type="text" name="apellidos" id="apellidos" 
                                   value="<?php echo htmlspecialchars($apellidos); ?>" 
                                   placeholder="Ej: Pérez García" required>
                        </div>
                    </div>
                    
                    <!-- Identificación y Email -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="numero_identificacion">Número de Identificación <span class="required">*</span></label>
                            <input type="text" name="numero_identificacion" id="numero_identificacion" 
                                   value="<?php echo htmlspecialchars($numero_identificacion); ?>" 
                                   placeholder="Ej: 12345678" required>
                            <div class="help-text">Debe ser único por evento</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="correo_electronico">Correo Electrónico <span class="required">*</span></label>
                            <input type="email" name="correo_electronico" id="correo_electronico" 
                                   value="<?php echo htmlspecialchars($correo_electronico); ?>" 
                                   placeholder="Ej: juan.perez@email.com" required>
                        </div>
                    </div>
                    
                    <!-- Teléfono y Rol -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="telefono">Teléfono</label>
                            <input type="tel" name="telefono" id="telefono" 
                                   value="<?php echo htmlspecialchars($telefono); ?>" 
                                   placeholder="Ej: 3001234567">
                            <div class="help-text">Campo opcional</div>
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
                            <div class="help-text">Define el tipo de certificado que recibirá</div>
                        </div>
                    </div>
                    
                    <!-- Institución -->
                    <div class="form-group">
                        <label for="institucion">Institución</label>
                        <input type="text" name="institucion" id="institucion" 
                               value="<?php echo htmlspecialchars($institucion); ?>" 
                               placeholder="Ej: Universidad Nacional">
                        <div class="help-text">Campo opcional - Institución a la que pertenece</div>
                    </div>

                    <!-- Botones de acción -->
                    <div class="form-actions">
                        <a href="listar.php" class="btn btn-secondary">❌ Cancelar</a>
                        <button type="submit" class="btn btn-primary">💾 Guardar Participante</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Validación del formulario en tiempo real
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const numeroIdentificacion = document.getElementById('numero_identificacion');
            const email = document.getElementById('correo_electronico');
            
            // Validar número de identificación (solo números y letras)
            numeroIdentificacion.addEventListener('input', function() {
                this.value = this.value.replace(/[^a-zA-Z0-9]/g, '');
            });
            
            // Validación antes de enviar
            form.addEventListener('submit', function(e) {
                const eventoId = document.getElementById('evento_id').value;
                const nombres = document.getElementById('nombres').value.trim();
                const apellidos = document.getElementById('apellidos').value.trim();
                const numeroId = numeroIdentificacion.value.trim();
                const emailValue = email.value.trim();
                const rol = document.getElementById('rol').value;
                
                // Validar campos obligatorios
                if (!eventoId || !nombres || !apellidos || !numeroId || !emailValue || !rol) {
                    e.preventDefault();
                    alert('❌ Por favor complete todos los campos obligatorios marcados con *');
                    return false;
                }
                
                // Validar longitud mínima
                if (nombres.length < 2 || apellidos.length < 2) {
                    e.preventDefault();
                    alert('❌ Los nombres y apellidos deben tener al menos 2 caracteres');
                    return false;
                }
                
                // Validar número de identificación
                if (numeroId.length < 4) {
                    e.preventDefault();
                    alert('❌ El número de identificación debe tener al menos 4 caracteres');
                    return false;
                }
                
                // Confirmar antes de guardar
                const nombreCompleto = nombres + ' ' + apellidos;
                if (!confirm(`¿Está seguro de agregar al participante ${nombreCompleto}?`)) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Auto-focus en el primer campo si no hay evento preseleccionado
            const eventoSelect = document.getElementById('evento_id');
            if (!eventoSelect.value) {
                eventoSelect.focus();
            } else {
                document.getElementById('nombres').focus();
            }
        });
    </script>
</body>
</html>