<?php
// admin/participantes/listar.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

// Filtros
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$buscar = isset($_GET['buscar']) ? limpiarDatos($_GET['buscar']) : '';
$evento_id = isset($_GET['evento_id']) ? intval($_GET['evento_id']) : 0;
$rol_filtro = isset($_GET['rol']) ? limpiarDatos($_GET['rol']) : '';

try {
    $db = Database::getInstance()->getConnection();
    
    // Obtener eventos para el filtro
    $stmt = $db->query("SELECT id, nombre FROM eventos ORDER BY fecha_inicio DESC");
    $eventos = $stmt->fetchAll();
    
    // Construir consulta con filtros
    $where_conditions = [];
    $params = [];
    
    if (!empty($buscar)) {
        $where_conditions[] = "(p.nombres LIKE ? OR p.apellidos LIKE ? OR p.numero_identificacion LIKE ? OR p.correo_electronico LIKE ?)";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
    }
    
    if ($evento_id > 0) {
        $where_conditions[] = "p.evento_id = ?";
        $params[] = $evento_id;
    }
    
    if (!empty($rol_filtro)) {
        $where_conditions[] = "p.rol = ?";
        $params[] = $rol_filtro;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Contar total de registros
    $count_query = "SELECT COUNT(*) FROM participantes p $where_clause";
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_registros = $stmt->fetchColumn();
    
    // Calcular paginación
    $paginacion = paginar($total_registros, $pagina);
    
    // Obtener participantes con información del evento
    $query = "
        SELECT p.*, e.nombre as evento_nombre, e.fecha_inicio, e.fecha_fin,
               (SELECT COUNT(*) FROM certificados c WHERE c.participante_id = p.id) as tiene_certificado
        FROM participantes p 
        LEFT JOIN eventos e ON p.evento_id = e.id 
        $where_clause 
        ORDER BY p.created_at DESC 
        LIMIT {$paginacion['registros_por_pagina']} OFFSET {$paginacion['offset']}
    ";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $participantes = $stmt->fetchAll();
    
    // Obtener roles únicos para el filtro
    $stmt = $db->query("SELECT DISTINCT rol FROM participantes ORDER BY rol");
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    $error = "Error al cargar los participantes: " . $e->getMessage();
}

$mensaje = obtenerMensaje();

// Manejar eliminación
if (isset($_GET['eliminar']) && $_SESSION['rol'] === 'admin') {
    $participante_id = intval($_GET['eliminar']);
    
    try {
        // Verificar si tiene certificados
        $stmt = $db->prepare("SELECT COUNT(*) FROM certificados WHERE participante_id = ?");
        $stmt->execute([$participante_id]);
        $tiene_certificados = $stmt->fetchColumn() > 0;
        
        if ($tiene_certificados) {
            mostrarMensaje('error', 'No se puede eliminar un participante que tiene certificados generados');
        } else {
            // Obtener datos para auditoría
            $stmt = $db->prepare("SELECT * FROM participantes WHERE id = ?");
            $stmt->execute([$participante_id]);
            $participante_data = $stmt->fetch();
            
            // Eliminar participante
            $stmt = $db->prepare("DELETE FROM participantes WHERE id = ?");
            $stmt->execute([$participante_id]);
            
            registrarAuditoria('DELETE', 'participantes', $participante_id, $participante_data);
            mostrarMensaje('success', 'Participante eliminado exitosamente');
        }
        
        header('Location: listar.php');
        exit;
        
    } catch (Exception $e) {
        mostrarMensaje('error', 'Error al eliminar el participante: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Participantes - Sistema de Certificados</title>
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
        
        .logo h1 { font-size: 1.5rem; }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .btn-logout:hover { background: rgba(255,255,255,0.3); }
        
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
            max-width: 1200px;
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
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            transition: transform 0.2s;
        }
        
        .btn-primary:hover { transform: translateY(-2px); }
        
        .filters {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
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
        
        input, select {
            padding: 0.75rem;
            border: 2px solid #e1e1e1;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn-secondary:hover { background: #5a6268; }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover { background-color: #f8f9fa; }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .badge-success { background-color: #d4edda; color: #155724; }
        .badge-warning { background-color: #fff3cd; color: #856404; }
        .badge-info { background-color: #d1ecf1; color: #0c5460; }
        .badge-secondary { background-color: #e2e3e5; color: #383d41; }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s;
        }
        
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-edit { background: #28a745; color: white; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-view { background: #17a2b8; color: white; }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
            text-align: center;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .stats-bar {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-around;
            text-align: center;
        }
        
        .stat-item strong {
            display: block;
            font-size: 1.5rem;
            color: #1976d2;
        }
        
        @media (max-width: 768px) {
            .filters-grid { grid-template-columns: 1fr; }
            .stats-bar { flex-direction: column; gap: 1rem; }
            .page-header { flex-direction: column; gap: 1rem; text-align: center; }
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
                <h2>Gestión de Participantes</h2>
            </div>
            <a href="cargar.php" class="btn-primary">📤 Cargar Participantes</a>
        </div>
        
        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $mensaje['tipo']; ?>">
                <?php echo $mensaje['texto']; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-bar">
            <div class="stat-item">
                <strong><?php echo $total_registros; ?></strong>
                <span>Total Participantes</span>
            </div>
            <div class="stat-item">
                <strong><?php echo count($eventos); ?></strong>
                <span>Eventos</span>
            </div>
            <div class="stat-item">
                <strong><?php echo count($roles); ?></strong>
                <span>Roles Diferentes</span>
            </div>
        </div>
        
        <div class="filters">
            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="buscar">Buscar participante</label>
                        <input type="text" id="buscar" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>" placeholder="Nombre, identificación o correo...">
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
                    
                    <div class="form-group">
                        <label for="rol">Rol</label>
                        <select id="rol" name="rol">
                            <option value="">Todos los roles</option>
                            <?php foreach ($roles as $rol): ?>
                                <option value="<?php echo htmlspecialchars($rol); ?>" <?php echo $rol_filtro === $rol ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rol); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-secondary">Filtrar</button>
                </div>
            </form>
        </div>
        
        <div class="card">
            <div class="table-responsive">
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
                                        <div>
                                            <strong><?php echo htmlspecialchars($participante['nombres'] . ' ' . $participante['apellidos']); ?></strong>
                                            <br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($participante['correo_electronico']); ?></small>
                                            <?php if ($participante['telefono']): ?>
                                                <br><small>📞 <?php echo htmlspecialchars($participante['telefono']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($participante['numero_identificacion']); ?></strong>
                                        <?php if ($participante['institucion']): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($participante['institucion']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($participante['evento_nombre']); ?></strong>
                                        <br>
                                        <small style="color: #666;">
                                            <?php echo formatearFecha($participante['fecha_inicio']); ?> - 
                                            <?php echo formatearFecha($participante['fecha_fin']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $rol_classes = [
                                            'Ponente' => 'badge-warning',
                                            'Organizador' => 'badge-info',
                                            'Moderador' => 'badge-info',
                                            'Participante' => 'badge-success',
                                            'Asistente' => 'badge-secondary'
                                        ];
                                        $badge_class = $rol_classes[$participante['rol']] ?? 'badge-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($participante['rol']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($participante['tiene_certificado'] > 0): ?>
                                            <span class="badge badge-success">✓ Generado</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($participante['tiene_certificado'] > 0): ?>
                                                <a href="../certificados/descargar.php?participante_id=<?php echo $participante['id']; ?>" class="btn-sm btn-view">Ver Certificado</a>
                                            <?php else: ?>
                                                <a href="../certificados/generar.php?participante_id=<?php echo $participante['id']; ?>" class="btn-sm btn-edit">Generar Certificado</a>
                                            <?php endif; ?>
                                            <?php if ($_SESSION['rol'] === 'admin'): ?>
                                                <a href="?eliminar=<?php echo $participante['id']; ?>" class="btn-sm btn-delete" onclick="return confirm('¿Está seguro de eliminar este participante?')">Eliminar</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div style="font-size: 3rem; margin-bottom: 1rem; color: #dee2e6;">👥</div>
                        <h3>No hay participantes registrados</h3>
                        <p>Comience cargando participantes desde un archivo CSV</p>
                        <a href="cargar.php" class="btn-primary" style="margin-top: 1rem; display: inline-block;">Cargar Participantes</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>