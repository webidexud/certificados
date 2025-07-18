<?php
// admin/index.php
require_once '../config/config.php';
require_once '../includes/funciones.php';

verificarAutenticacion();

// Obtener estadísticas del dashboard
try {
    $db = Database::getInstance()->getConnection();
    
    // Total de eventos
    $stmt = $db->query("SELECT COUNT(*) as total FROM eventos");
    $total_eventos = $stmt->fetch()['total'];
    
    // Total de participantes
    $stmt = $db->query("SELECT COUNT(*) as total FROM participantes");
    $total_participantes = $stmt->fetch()['total'];
    
    // Total de certificados generados
    $stmt = $db->query("SELECT COUNT(*) as total FROM certificados");
    $total_certificados = $stmt->fetch()['total'];
    
    // Eventos activos
    $stmt = $db->query("SELECT COUNT(*) as total FROM eventos WHERE estado = 'activo'");
    $eventos_activos = $stmt->fetch()['total'];
    
    // Últimos eventos creados
    $stmt = $db->query("SELECT * FROM eventos ORDER BY created_at DESC LIMIT 5");
    $ultimos_eventos = $stmt->fetchAll();
    
    // Certificados generados hoy
    $stmt = $db->query("SELECT COUNT(*) as total FROM certificados WHERE DATE(fecha_generacion) = CURDATE()");
    $certificados_hoy = $stmt->fetch()['total'];
    
} catch (Exception $e) {
    $error = "Error al cargar las estadísticas: " . $e->getMessage();
}

$mensaje = obtenerMensaje();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - Sistema de Certificados</title>
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
        
        .user-info span {
            font-size: 0.9rem;
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
        
        .page-title {
            margin-bottom: 2rem;
        }
        
        .page-title h2 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .page-title p {
            color: #666;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        .card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            color: #333;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .event-item {
            padding: 1rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .event-item:last-child {
            border-bottom: none;
        }
        
        .event-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .event-date {
            color: #666;
            font-size: 0.9rem;
        }
        
        .quick-actions {
            display: grid;
            gap: 1rem;
        }
        
        .action-btn {
            display: block;
            padding: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
            transition: transform 0.3s;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
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
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav ul {
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
                <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul>
                <li><a href="index.php" class="active">Dashboard</a></li>
                <li><a href="eventos/listar.php">Eventos</a></li>
                <li><a href="participantes/listar.php">Participantes</a></li>
                <li><a href="certificados/generar.php">Certificados</a></li>
            </ul>
        </div>
    </nav>
    
    <div class="container">
        <div class="page-title">
            <h2>Panel de Control</h2>
            <p>Resumen general del sistema de certificados digitales</p>
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
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_eventos; ?></div>
                <div class="stat-label">Total Eventos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $eventos_activos; ?></div>
                <div class="stat-label">Eventos Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_participantes; ?></div>
                <div class="stat-label">Participantes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_certificados; ?></div>
                <div class="stat-label">Certificados Generados</div>
            </div>
        </div>
        
        <div class="content-grid">
            <div class="card">
                <h3>Últimos Eventos Creados</h3>
                <?php if (!empty($ultimos_eventos)): ?>
                    <?php foreach ($ultimos_eventos as $evento): ?>
                        <div class="event-item">
                            <div class="event-name"><?php echo htmlspecialchars($evento['nombre']); ?></div>
                            <div class="event-date">
                                <?php echo formatearFecha($evento['fecha_inicio']); ?> - 
                                <?php echo formatearFecha($evento['fecha_fin']); ?>
                                | <?php echo ucfirst($evento['modalidad']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #666; text-align: center; padding: 2rem;">No hay eventos registrados</p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h3>Acciones Rápidas</h3>
                <div class="quick-actions">
                    <a href="eventos/crear.php" class="action-btn">+ Crear Evento</a>
                    <a href="participantes/cargar.php" class="action-btn">+ Cargar Participantes</a>
                    <a href="certificados/generar.php" class="action-btn">🏆 Generar Certificados</a>
                    <a href="../public/consulta.php" class="action-btn" target="_blank">🔍 Ver Consulta Pública</a>
                </div>
                
                <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #f0f0f0;">
                    <h4 style="color: #333; margin-bottom: 1rem;">Estadísticas de Hoy</h4>
                    <p style="color: #666;">Certificados generados: <strong><?php echo $certificados_hoy; ?></strong></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>