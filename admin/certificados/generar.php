<?php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

// Manejar mensajes de la sesión
$mensaje = null;
if (isset($_SESSION['success_mensaje'])) {
    $mensaje = ['tipo' => 'success', 'texto' => $_SESSION['success_mensaje']];
    unset($_SESSION['success_mensaje']);
} elseif (isset($_SESSION['error_mensaje'])) {
    $mensaje = ['tipo' => 'error', 'texto' => $_SESSION['error_mensaje']];
    unset($_SESSION['error_mensaje']);
}

$error = '';
$participantes = [];
$eventos = [];
$evento_seleccionado = null;
$estadisticas = [];

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener eventos para el selector
    $stmt = $db->query("SELECT id, nombre, fecha_inicio, fecha_fin, estado FROM eventos ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Error al cargar eventos: " . $e->getMessage();
}

// Si hay un evento seleccionado, cargar participantes y estadísticas
if (isset($_GET['evento_id']) && is_numeric($_GET['evento_id'])) {
    $evento_id = (int)$_GET['evento_id'];
    
    try {
        // Obtener información del evento
        $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
        $stmt->execute([$evento_id]);
        $evento_seleccionado = $stmt->fetch();
        
        if ($evento_seleccionado) {
            // Obtener participantes con información de certificados
            $stmt = $db->prepare("
                SELECT p.*, 
                       e.nombre as evento_nombre,
                       c.id as certificado_id,
                       c.codigo_verificacion,
                       c.fecha_generacion,
                       c.tipo_archivo,
                       c.estado as certificado_estado
                FROM participantes p
                JOIN eventos e ON p.evento_id = e.id
                LEFT JOIN certificados c ON p.id = c.participante_id
                WHERE p.evento_id = ?
                ORDER BY p.apellidos, p.nombres
            ");
            $stmt->execute([$evento_id]);
            $participantes = $stmt->fetchAll();
            
            // Calcular estadísticas
            $total_participantes = count($participantes);
            $con_certificado = 0;
            $sin_certificado = 0;
            $por_rol = [];
            
            foreach ($participantes as $participante) {
                $rol = $participante['rol'];
                if (!isset($por_rol[$rol])) {
                    $por_rol[$rol] = ['total' => 0, 'con_certificado' => 0, 'sin_certificado' => 0];
                }
                $por_rol[$rol]['total']++;
                
                if ($participante['certificado_id']) {
                    $con_certificado++;
                    $por_rol[$rol]['con_certificado']++;
                } else {
                    $sin_certificado++;
                    $por_rol[$rol]['sin_certificado']++;
                }
            }
            
            $estadisticas = [
                'total_participantes' => $total_participantes,
                'con_certificado' => $con_certificado,
                'sin_certificado' => $sin_certificado,
                'porcentaje_completado' => $total_participantes > 0 ? round(($con_certificado / $total_participantes) * 100, 1) : 0,
                'por_rol' => $por_rol
            ];
        }
    } catch (Exception $e) {
        $error = "Error al cargar datos del evento: " . $e->getMessage();
    }
}

// Procesar acciones masivas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion = $_POST['accion'];
    $participantes_seleccionados = $_POST['participantes'] ?? [];
    $evento_id = $_POST['evento_id'] ?? null;
    
    if (empty($participantes_seleccionados) || !$evento_id) {
        $_SESSION['error_mensaje'] = 'Debe seleccionar al menos un participante';
    } else {
        try {
            if ($accion === 'generar_certificados') {
                $generados = 0;
                $errores = 0;
                
                foreach ($participantes_seleccionados as $participante_id) {
                    // Aquí iría la lógica de generación individual
                    // Por ahora simulamos el resultado
                    $generados++;
                }
                
                $_SESSION['success_mensaje'] = "Se generaron $generados certificados exitosamente";
                if ($errores > 0) {
                    $_SESSION['error_mensaje'] = "Hubo $errores errores durante la generación";
                }
                
            } elseif ($accion === 'eliminar_certificados') {
                $placeholders = str_repeat('?,', count($participantes_seleccionados) - 1) . '?';
                $stmt = $db->prepare("DELETE FROM certificados WHERE participante_id IN ($placeholders)");
                $stmt->execute($participantes_seleccionados);
                $eliminados = $stmt->rowCount();
                
                $_SESSION['success_mensaje'] = "Se eliminaron $eliminados certificados exitosamente";
            }
            
            // Recargar página para mostrar cambios
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error_mensaje'] = 'Error al procesar la acción: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Certificados - Sistema de Certificados</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
            line-height: 1.5;
        }
        
        .header {
            background: #fff;
            border-bottom: 1px solid #e1e5e9;
            padding: 1rem 0;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #718096;
        }
        
        .logout {
            color: #e53e3e;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid #e53e3e;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .logout:hover {
            background: #e53e3e;
            color: white;
        }
        
        .nav {
            background: #fff;
            border-bottom: 1px solid #e1e5e9;
            padding: 0.5rem 0;
        }
        
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .nav ul {
            list-style: none;
            display: flex;
            gap: 2rem;
        }
        
        .nav a {
            color: #718096;
            text-decoration: none;
            padding: 0.5rem 0;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .nav a:hover,
        .nav a.active {
            color: #2d3748;
            border-bottom-color: #4299e1;
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 1rem;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        
        .btn-primary:hover {
            background: #3182ce;
        }
        
        .btn-success {
            background: #38a169;
            color: white;
        }
        
        .btn-success:hover {
            background: #2f855a;
        }
        
        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c53030;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border-left-color: #38a169;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left-color: #e53e3e;
        }
        
        .event-selector {
            background: white;
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid #e1e5e9;
        }
        
        .event-selector h3 {
            margin-bottom: 1rem;
            color: #2d3748;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #4a5568;
            font-size: 0.9rem;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 2px rgba(66, 153, 225, 0.1);
        }
        
        .stats-container {
            background: white;
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid #e1e5e9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: #4299e1;
            color: white;
            padding: 1.5rem;
            border-radius: 6px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .role-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .role-card {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 4px;
            border-left: 4px solid #4299e1;
        }
        
        .role-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .role-progress {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #718096;
        }
        
        .mass-controls {
            background: white;
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid #e1e5e9;
        }
        
        .controls-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .selection-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .table-container {
            background: white;
            border-radius: 6px;
            border: 1px solid #e1e5e9;
            overflow: hidden;
        }
        
        .table-header {
            padding: 1rem 1.5rem;
            background: #f7fafc;
            border-bottom: 1px solid #e1e5e9;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f7fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.85rem;
            border-bottom: 1px solid #e1e5e9;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #f1f3f4;
        }
        
        tr:hover {
            background: #f7fafc;
        }
        
        .participant-name {
            font-weight: 500;
            color: #2d3748;
        }
        
        .participant-email {
            font-size: 0.85rem;
            color: #718096;
            margin-top: 0.25rem;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-pending {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .badge-role {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        input[type="checkbox"] {
            width: auto;
            margin: 0;
            transform: scale(1.2);
            cursor: pointer;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #718096;
        }
        
        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #4a5568;
        }
        
        .counter-text {
            margin-top: 1rem;
            font-weight: 600;
            color: #4299e1;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .selection-controls {
                flex-direction: column;
            }
            
            .controls-header {
                flex-direction: column;
            }
            
            table {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Sistema de Certificados</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
                <a href="../logout.php" class="logout">Salir</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="../index.php">Dashboard</a></li>
                <li><a href="../eventos/listar.php">Eventos</a></li>
                <li><a href="../participantes/listar.php">Participantes</a></li>
                <li><a href="generar.php" class="active">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <h2>Gestión de Certificados</h2>
        </div>
        
        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $mensaje['tipo']; ?>">
                <?php echo htmlspecialchars($mensaje['texto']); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Selector de Evento -->
        <div class="event-selector">
            <h3>Seleccionar Evento</h3>
            <form method="GET">
                <div class="form-group">
                    <label for="evento_id">Evento</label>
                    <select id="evento_id" name="evento_id" onchange="this.form.submit()">
                        <option value="">Seleccione un evento...</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?php echo $evento['id']; ?>" 
                                    <?php echo (isset($_GET['evento_id']) && $_GET['evento_id'] == $evento['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($evento['nombre']); ?> 
                                (<?php echo date('d/m/Y', strtotime($evento['fecha_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($evento['fecha_fin'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <?php if ($evento_seleccionado && !empty($participantes)): ?>
            <!-- Estadísticas del Evento -->
            <div class="stats-container">
                <h3>Estadísticas del Evento</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['total_participantes']; ?></div>
                        <div class="stat-label">Total Participantes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['con_certificado']; ?></div>
                        <div class="stat-label">Con Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['sin_certificado']; ?></div>
                        <div class="stat-label">Sin Certificado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $estadisticas['porcentaje_completado']; ?>%</div>
                        <div class="stat-label">Progreso</div>
                    </div>
                </div>
                
                <?php if (!empty($estadisticas['por_rol'])): ?>
                    <h4 style="margin-bottom: 1rem; color: #2d3748;">Estadísticas por Rol</h4>
                    <div class="role-stats">
                        <?php foreach ($estadisticas['por_rol'] as $rol => $datos): ?>
                            <div class="role-card">
                                <div class="role-name"><?php echo htmlspecialchars($rol); ?></div>
                                <div class="role-progress">
                                    <span>Total: <?php echo $datos['total']; ?></span>
                                    <span>Con certificado: <?php echo $datos['con_certificado']; ?></span>
                                    <span>Pendientes: <?php echo $datos['sin_certificado']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Controles Masivos -->
            <div class="mass-controls">
                <div class="controls-header">
                    <h3>Acciones Masivas</h3>
                    <div class="selection-controls">
                        <button type="button" class="btn btn-sm btn-primary" onclick="seleccionarTodos()">
                            Seleccionar Todos
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="deseleccionarTodos()">
                            Deseleccionar Todos
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="seleccionarSinCertificado()">
                            Solo Sin Certificado
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="seleccionarConCertificado()">
                            Solo Con Certificado
                        </button>
                    </div>
                </div>
                
                <form method="POST" id="formAccionMasiva">
                    <input type="hidden" name="evento_id" value="<?php echo $evento_seleccionado['id']; ?>">
                    <div class="selection-controls">
                        <button type="submit" name="accion" value="generar_certificados" class="btn btn-success" 
                                onclick="return confirmarAccion('generar')">
                            Generar Certificados Seleccionados
                        </button>
                        <button type="submit" name="accion" value="eliminar_certificados" class="btn btn-danger" 
                                onclick="return confirmarAccion('eliminar')">
                            Eliminar Certificados Seleccionados
                        </button>
                    </div>
                    <div id="contadorSeleccionados" class="counter-text">
                        0 participantes seleccionados
                    </div>
                </form>
            </div>
            
            <!-- Tabla de Participantes -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Participantes del Evento</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                            </th>
                            <th>Participante</th>
                            <th>Identificación</th>
                            <th>Rol</th>
                            <th>Estado del Certificado</th>
                            <th>Fecha Generación</th>
                            <th>Código Verificación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participantes as $participante): ?>
                            <tr>
                                <td class="checkbox-container">
                                    <input type="checkbox" 
                                           name="participantes[]" 
                                           value="<?php echo $participante['id']; ?>"
                                           data-tiene-certificado="<?php echo $participante['certificado_id'] ? 'true' : 'false'; ?>"
                                           class="participante-checkbox"
                                           onchange="actualizarContador()">
                                </td>
                                <td>
                                    <div class="participant-name">
                                        <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>
                                    </div>
                                    <?php if ($participante['correo_electronico']): ?>
                                        <div class="participant-email">
                                            <?php echo htmlspecialchars($participante['correo_electronico']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($participante['numero_identificacion']); ?>
                                </td>
                                <td>
                                    <span class="badge badge-role">
                                        <?php echo htmlspecialchars($participante['rol']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($participante['certificado_id']): ?>
                                        <span class="badge badge-success">Generado</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($participante['fecha_generacion']): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($participante['fecha_generacion'])); ?>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($participante['codigo_verificacion']): ?>
                                        <code style="background: #f7fafc; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.8rem;">
                                            <?php echo htmlspecialchars($participante['codigo_verificacion']); ?>
                                        </code>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($evento_seleccionado && empty($participantes)): ?>
            <div class="empty-state">
                <h3>No hay participantes en este evento</h3>
                <p>Para generar certificados, primero debe cargar los participantes del evento.</p>
                <a href="../participantes/cargar.php?evento_id=<?php echo $evento_seleccionado['id']; ?>" class="btn btn-primary">
                    Cargar Participantes
                </a>
            </div>
            
        <?php elseif (!$evento_seleccionado): ?>
            <div class="empty-state">
                <h3>Seleccione un evento</h3>
                <p>Para comenzar la gestión de certificados, seleccione un evento de la lista desplegable.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        let participantesSeleccionados = 0;
        
        function seleccionarTodos() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = true);
            document.getElementById('selectAll').checked = true;
            actualizarContador();
        }
        
        function deseleccionarTodos() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = false);
            document.getElementById('selectAll').checked = false;
            actualizarContador();
        }
        
        function seleccionarSinCertificado() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = checkbox.dataset.tieneCertificado === 'false';
            });
            actualizarContador();
        }
        
        function seleccionarConCertificado() {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = checkbox.dataset.tieneCertificado === 'true';
            });
            actualizarContador();
        }
        
        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.participante-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            actualizarContador();
        }
        
        function actualizarContador() {
            const checkboxes = document.querySelectorAll('.participante-checkbox:checked');
            participantesSeleccionados = checkboxes.length;
            
            const contador = document.getElementById('contadorSeleccionados');
            if (participantesSeleccionados === 0) {
                contador.textContent = '0 participantes seleccionados';
                contador.style.color = '#999';
            } else {
                contador.textContent = `${participantesSeleccionados} participante${participantesSeleccionados > 1 ? 's' : ''} seleccionado${participantesSeleccionados > 1 ? 's' : ''}`;
                contador.style.color = '#4299e1';
            }
            
            // Actualizar estado del checkbox principal
            const totalCheckboxes = document.querySelectorAll('.participante-checkbox').length;
            const selectAllCheckbox = document.getElementById('selectAll');
            
            if (participantesSeleccionados === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (participantesSeleccionados === totalCheckboxes) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }
        
        function confirmarAccion(accion) {
            if (participantesSeleccionados === 0) {
                alert('Debe seleccionar al menos un participante.');
                return false;
            }
            
            let mensaje = '';
            if (accion === 'generar') {
                mensaje = `¿Está seguro de generar certificados para ${participantesSeleccionados} participante${participantesSeleccionados > 1 ? 's' : ''}?`;
            } else if (accion === 'eliminar') {
                mensaje = `¿Está seguro de eliminar los certificados de ${participantesSeleccionados} participante${participantesSeleccionados > 1 ? 's' : ''}?\n\nEsta acción no se puede deshacer.`;
            }
            
            return confirm(mensaje);
        }
        
        function mostrarLoading(boton, texto) {
            boton.disabled = true;
            boton.innerHTML = `${texto}...`;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar contador
            actualizarContador();
            
            // Auto-ocultar mensajes después de 5 segundos
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.3s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
            
            // Manejar envío del formulario de acciones masivas
            const form = document.getElementById('formAccionMasiva');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const action = e.submitter.value;
                    const boton = e.submitter;
                    
                    if (action === 'generar_certificados') {
                        mostrarLoading(boton, 'Generando certificados');
                    } else if (action === 'eliminar_certificados') {
                        mostrarLoading(boton, 'Eliminando certificados');
                    }
                });
            }
        });
    </script>
</body>
</html>