<?php
// admin/eventos/listar.php
require_once '../../config/config.php';
require_once '../../includes/funciones.php';

verificarAutenticacion();

// Paginación
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$buscar = isset($_GET['buscar']) ? limpiarDatos($_GET['buscar']) : '';
$estado_filtro = isset($_GET['estado']) ? $_GET['estado'] : '';

try {
    $db = Database::getInstance()->getConnection();
    
    // Construir consulta con filtros
    $where_conditions = [];
    $params = [];
    
    if (!empty($buscar)) {
        $where_conditions[] = "(nombre LIKE ? OR entidad_organizadora LIKE ?)";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
    }
    
    if (!empty($estado_filtro)) {
        $where_conditions[] = "estado = ?";
        $params[] = $estado_filtro;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Contar total de registros
    $count_query = "SELECT COUNT(*) FROM eventos $where_clause";
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_registros = $stmt->fetchColumn();
    
    // Calcular paginación
    $paginacion = paginar($total_registros, $pagina);
    
    // Obtener eventos
    $query = "SELECT * FROM eventos $where_clause ORDER BY created_at DESC LIMIT {$paginacion['registros_por_pagina']} OFFSET {$paginacion['offset']}";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $eventos = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Error al cargar los eventos: " . $e->getMessage();
}

$mensaje = obtenerMensaje();

// Manejar eliminación
if (isset($_GET['eliminar']) && $_SESSION['rol'] === 'admin') {
    $evento_id = intval($_GET['eliminar']);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Verificar si el evento tiene participantes
        $stmt = $db->prepare("SELECT COUNT(*) FROM participantes WHERE evento_id = ?");
        $stmt->execute([$evento_id]);
        $tiene_participantes = $stmt->fetchColumn() > 0;
        
        if ($tiene_participantes) {
            mostrarMensaje('error', 'No se puede eliminar un evento que tiene participantes registrados');
        } else {
            // Obtener datos del evento para auditoría
            $stmt = $db->prepare("SELECT * FROM eventos WHERE id = ?");
            $stmt->execute([$evento_id]);
            $evento_data = $stmt->fetch();
            
            // Eliminar evento
            $stmt = $db->prepare("DELETE FROM eventos WHERE id = ?");
            $stmt->execute([$evento_id]);
            
            registrarAuditoria('DELETE', 'eventos', $evento_id, $evento_data);
            mostrarMensaje('success', 'Evento eliminado exitosamente');
        }
        
        header('Location: listar.php');
        exit;
        
    } catch (Exception $e) {
        mostrarMensaje('error', 'Error al eliminar el evento: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Eventos - Sistema de Certificados</title>
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
        
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        
        .filters {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
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
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
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
        
        tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .badge-info {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-secondary {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
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
        
        .btn-sm:hover {
            transform: translateY(-1px);
        }
        
        .btn-edit {
            background: #28a745;
            color: white;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-view {
            background: #17a2b8;
            color: white;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background-color: #e9ecef;
        }
        
        .pagination .active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .pagination .disabled {
            color: #6c757d;
            background-color: #e9ecef;
            border-color: #dee2e6;
        }
        
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
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
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
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            th, td {
                padding: 0.5rem;
            }
            
            .actions {
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
                <span>Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
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
                <h2>Gestión de Eventos</h2>
            </div>
            <a href="crear.php" class="btn-primary">+ Crear Evento</a>
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
        
        <div class="filters">
            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="buscar">Buscar evento</label>
                        <input type="text" id="buscar" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>" placeholder="Nombre del evento o entidad organizadora...">
                    </div>
                    
                    <div class="form-group">
                        <label for="estado">Estado</label>
                        <select id="estado" name="estado">
                            <option value="">Todos los estados</option>
                            <option value="activo" <?php echo $estado_filtro === 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo $estado_filtro === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-secondary">Filtrar</button>
                </div>
            </form>
        </div>
        
        <div class="card">
            <div class="table-responsive">
                <?php if (!empty($eventos)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Evento</th>
                                <th>Fechas</th>
                                <th>Modalidad</th>
                                <th>Participantes</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventos as $evento): ?>
                                <?php
                                // Contar participantes
                                $stmt = $db->prepare("SELECT COUNT(*) FROM participantes WHERE evento_id = ?");
                                $stmt->execute([$evento['id']]);
                                $total_participantes = $stmt->fetchColumn();
                                
                                // Contar certificados
                                $stmt = $db->prepare("SELECT COUNT(*) FROM certificados WHERE evento_id = ?");
                                $stmt->execute([$evento['id']]);
                                $total_certificados = $stmt->fetchColumn();
                                ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($evento['nombre']); ?></strong>
                                            <br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($evento['entidad_organizadora']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo formatearFecha($evento['fecha_inicio']); ?><br>
                                        <small style="color: #666;">al <?php echo formatearFecha($evento['fecha_fin']); ?></small>
                                        <?php if ($evento['horas_duracion']): ?>
                                            <br><small>(<?php echo $evento['horas_duracion']; ?>h)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $modalidad_class = [
                                            'presencial' => 'badge-info',
                                            'virtual' => 'badge-success',
                                            'hibrida' => 'badge-warning'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $modalidad_class[$evento['modalidad']] ?? 'badge-secondary'; ?>">
                                            <?php echo ucfirst($evento['modalidad']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo $total_participantes; ?></strong> participantes<br>
                                        <small style="color: #666;"><?php echo $total_certificados; ?> certificados</small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $evento['estado'] === 'activo' ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo ucfirst($evento['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="editar.php?id=<?php echo $evento['id']; ?>" class="btn-sm btn-edit">Editar</a>
                                            <a href="../participantes/listar.php?evento_id=<?php echo $evento['id']; ?>" class="btn-sm btn-view">Ver Participantes</a>
                                            <?php if ($_SESSION['rol'] === 'admin'): ?>
                                                <a href="?eliminar=<?php echo $evento['id']; ?>" class="btn-sm btn-delete" onclick="return confirm('¿Está seguro de eliminar este evento?')">Eliminar</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div style="font-size: 3rem; margin-bottom: 1rem; color: #dee2e6;">📅</div>
                        <h3>No hay eventos registrados</h3>
                        <p>Comience creando su primer evento</p>
                        <a href="crear.php" class="btn-primary" style="margin-top: 1rem; display: inline-block;">Crear Primer Evento</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($paginacion['total_paginas'] > 1): ?>
            <div class="pagination">
                <?php if ($paginacion['tiene_anterior']): ?>
                    <a href="?pagina=<?php echo $pagina - 1; ?>&buscar=<?php echo urlencode($buscar); ?>&estado=<?php echo urlencode($estado_filtro); ?>">« Anterior</a>
                <?php else: ?>
                    <span class="disabled">« Anterior</span>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $paginacion['total_paginas']; $i++): ?>
                    <?php if ($i == $pagina): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?pagina=<?php echo $i; ?>&buscar=<?php echo urlencode($buscar); ?>&estado=<?php echo urlencode($estado_filtro); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($paginacion['tiene_siguiente']): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&buscar=<?php echo urlencode($buscar); ?>&estado=<?php echo urlencode($estado_filtro); ?>">Siguiente »</a>
                <?php else: ?>
                    <span class="disabled">Siguiente »</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>