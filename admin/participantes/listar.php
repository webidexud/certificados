<?php
// admin/participantes/listar.php - VERSIÓN MINIMALISTA
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

// Filtros simples
$buscar = isset($_GET['buscar']) ? limpiarDatos($_GET['buscar']) : '';
$evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : 0;

$error = '';
$participantes = [];
$eventos = [];

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener eventos para el filtro
    $stmt = $db->query("SELECT id, nombre FROM eventos ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
    
    // Construir consulta con filtros
    $where_conditions = [];
    $params = [];
    
    if (!empty($buscar)) {
        $where_conditions[] = "(p.nombres LIKE ? OR p.apellidos LIKE ? OR p.numero_identificacion LIKE ?)";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
    }
    
    if ($evento_id > 0) {
        $where_conditions[] = "p.evento_id = ?";
        $params[] = $evento_id;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Obtener participantes
    $query = "
        SELECT p.*, e.nombre as evento_nombre,
               (SELECT COUNT(*) FROM certificados c WHERE c.participante_id = p.id) as tiene_certificado
        FROM participantes p 
        LEFT JOIN eventos e ON p.evento_id = e.id 
        $where_clause 
        ORDER BY p.nombres, p.apellidos
    ";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $participantes = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Manejar eliminación
if (isset($_GET['eliminar']) && $_SESSION['rol'] === 'admin') {
    $participante_id = intval($_GET['eliminar']);
    
    try {
        // Verificar si tiene certificados
        $stmt = $db->prepare("SELECT COUNT(*) FROM certificados WHERE participante_id = ?");
        $stmt->execute([$participante_id]);
        $tiene_certificados = $stmt->fetchColumn();
        
        if ($tiene_certificados > 0) {
            $_SESSION['error_mensaje'] = 'No se puede eliminar: el participante tiene certificados';
        } else {
            $stmt = $db->prepare("DELETE FROM participantes WHERE id = ?");
            $stmt->execute([$participante_id]);
            $_SESSION['success_mensaje'] = 'Participante eliminado';
        }
        
        header('Location: listar.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_mensaje'] = 'Error al eliminar: ' . $e->getMessage();
        header('Location: listar.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participantes - Sistema de Certificados</title>
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
        
        .filters {
            background: white;
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid #e1e5e9;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #4a5568;
            font-size: 0.9rem;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 2px rgba(66, 153, 225, 0.1);
        }
        
        .table-container {
            background: white;
            border-radius: 6px;
            border: 1px solid #e1e5e9;
            overflow: hidden;
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
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 3px;
            text-decoration: none;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-generate {
            background: #38a169;
            color: white;
        }
        
        .btn-generate:hover {
            background: #2f855a;
        }
        
        .btn-edit {
            background: #4299e1;
            color: white;
        }
        
        .btn-edit:hover {
            background: #3182ce;
        }
        
        .btn-delete {
            background: #e53e3e;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c53030;
        }
        
        .btn-view {
            background: #718096;
            color: white;
        }
        
        .btn-view:hover {
            background: #4a5568;
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
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .actions {
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
                <li><a href="listar.php" class="active">Participantes</a></li>
                <li><a href="../certificados/generar.php">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-header">
            <h2>Participantes</h2>
            <a href="cargar.php" class="btn btn-primary">Cargar participantes</a>
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
        
        <div class="filters">
            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="buscar">Buscar</label>
                        <input type="text" id="buscar" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>" placeholder="Nombre o identificación">
                    </div>
                    
                    <div class="form-group">
                        <label for="evento_id">Evento</label>
                        <select id="evento_id" name="evento_id">
                            <option value="">Todos los eventos</option>
                            <?php foreach ($eventos as $evento): ?>
                                <option value="<?php echo $evento['id']; ?>" <?php echo $evento_id == $evento['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($evento['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>
        </div>
        
        <div class="table-container">
            <?php if (!empty($participantes)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Participante</th>
                            <th>Identificación</th>
                            <th>Evento</th>
                            <th>Rol</th>
                            <th>Certificado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participantes as $participante): ?>
                            <tr>
                                <td>
                                    <div class="participant-name">
                                        <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>
                                    </div>
                                    <div class="participant-email">
                                        <?php echo htmlspecialchars($participante['correo_electronico']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($participante['numero_identificacion']); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($participante['evento_nombre']); ?>
                                </td>
                                <td>
                                    <span class="badge badge-role">
                                        <?php echo htmlspecialchars($participante['rol']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($participante['tiene_certificado'] > 0): ?>
                                        <span class="badge badge-success">Generado</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <?php if ($participante['tiene_certificado'] > 0): ?>
                                            <a href="../certificados/descargar.php?participante_id=<?php echo $participante['id']; ?>" class="btn-sm btn-view">Ver</a>
                                        <?php else: ?>
                                            <form method="POST" action="generar_individual.php" style="display: inline;">
                                                <input type="hidden" name="participante_id" value="<?php echo $participante['id']; ?>">
                                                <button type="submit" class="btn-sm btn-generate" onclick="return confirm('¿Generar certificado para <?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?>?')">
                                                    Generar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <a href="editar.php?id=<?php echo $participante['id']; ?>" class="btn-sm btn-edit">Editar</a>
                                        
                                        <?php if ($_SESSION['rol'] === 'admin'): ?>
                                            <a href="?eliminar=<?php echo $participante['id']; ?>" class="btn-sm btn-delete" onclick="return confirm('¿Eliminar participante?')">Eliminar</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No hay participantes</h3>
                    <p>No se encontraron participantes que coincidan con los criterios de búsqueda.</p>
                    <a href="cargar.php" class="btn btn-primary" style="margin-top: 1rem;">Cargar participantes</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-ocultar mensajes después de 5 segundos
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.3s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
        
        // Envío de formularios de generación
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form[action="generar_individual.php"]');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const btn = this.querySelector('button[type="submit"]');
                    btn.innerHTML = 'Generando...';
                    btn.disabled = true;
                });
            });
        });
    </script>
</body>
</html>