<?php
// public/index.php
require_once '../config/config.php';
require_once '../includes/funciones.php';

// Obtener estadísticas públicas
try {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM eventos WHERE estado = 'activo'");
    $eventos_activos = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM certificados");
    $certificados_emitidos = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(DISTINCT evento_id) as total FROM participantes");
    $eventos_con_participantes = $stmt->fetch()['total'];
    
    // Últimos eventos públicos (opcional)
    $stmt = $db->query("
        SELECT nombre, fecha_inicio, fecha_fin, modalidad, entidad_organizadora 
        FROM eventos 
        WHERE estado = 'activo' AND fecha_fin >= CURDATE() 
        ORDER BY fecha_inicio DESC 
        LIMIT 3
    ");
    $eventos_recientes = $stmt->fetchAll();
    
} catch (Exception $e) {
    $eventos_activos = 0;
    $certificados_emitidos = 0;
    $eventos_con_participantes = 0;
    $eventos_recientes = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Certificados Digitales</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="3" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="70" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="90" cy="90" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="10" cy="80" r="1.5" fill="rgba(255,255,255,0.1)"/></svg>');
            animation: float 20s linear infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
            100% { transform: translateY(0px) rotate(360deg); }
        }
        
        .hero-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        
        .hero-text h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }
        
        .hero-text p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .cta-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }
        
        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid white;
        }
        
        .btn-secondary:hover {
            background: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .hero-visual {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .certificate-preview {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: perspective(1000px) rotateY(-15deg) rotateX(5deg);
            transition: transform 0.3s ease;
            color: #333;
            max-width: 400px;
        }
        
        .certificate-preview:hover {
            transform: perspective(1000px) rotateY(0deg) rotateX(0deg);
        }
        
        .certificate-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .certificate-title {
            font-size: 1.5rem;
            color: #667eea;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .certificate-subtitle {
            color: #666;
            font-size: 0.9rem;
        }
        
        .certificate-body {
            text-align: center;
        }
        
        .certificate-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin: 1rem 0;
            padding: 0.5rem;
            border-bottom: 2px solid #667eea;
        }
        
        .certificate-details {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .stats-section {
            padding: 4rem 0;
            background: #f8f9fa;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 4rem;
        }
        
        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        .features-section {
            padding: 4rem 0;
            background: white;
        }
        
        .section-title {
            text-align: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 3rem;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            padding: 2rem;
            border-radius: 15px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transition: all 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .feature-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
        }
        
        .feature-description {
            color: #666;
            line-height: 1.6;
        }
        
        .footer {
            background: #333;
            color: white;
            padding: 2rem 0;
            text-align: center;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .footer-section h3 {
            color: #667eea;
            margin-bottom: 1rem;
        }
        
        .footer-section a {
            color: #ccc;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-section a:hover {
            color: #667eea;
        }
        
        .footer-bottom {
            border-top: 1px solid #555;
            padding-top: 2rem;
            color: #ccc;
        }
        
        @media (max-width: 768px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 2rem;
            }
            
            .hero-text h1 {
                font-size: 2.5rem;
            }
            
            .cta-buttons {
                justify-content: center;
            }
            
            .certificate-preview {
                transform: none;
                max-width: 300px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <div class="hero-text">
                <h1>Certificados Digitales Verificables</h1>
                <p>Genera, gestiona y verifica certificados digitales de forma segura y eficiente. Ideal para eventos académicos, formativos e institucionales.</p>
                <div class="cta-buttons">
                    <a href="consulta.php" class="btn btn-primary">
                        🔍 Consultar Certificado
                    </a>
                    <a href="verificar.php" class="btn btn-secondary">
                        ✓ Verificar Autenticidad
                    </a>
                </div>
            </div>
            <div class="hero-visual">
                <div class="certificate-preview">
                    <div class="certificate-header">
                        <div class="certificate-title">CERTIFICADO DE PARTICIPACIÓN</div>
                        <div class="certificate-subtitle">Sistema de Certificados Digitales</div>
                    </div>
                    <div class="certificate-body">
                        <div>Se certifica que</div>
                        <div class="certificate-name">NOMBRE DEL PARTICIPANTE</div>
                        <div class="certificate-details">
                            participó en el evento<br>
                            <strong>"Nombre del Evento"</strong><br>
                            realizado del 01 al 03 de junio de 2025<br><br>
                            <small>Código de verificación: ABC123XYZ</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $eventos_activos; ?></div>
                    <div class="stat-label">Eventos Activos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $certificados_emitidos; ?></div>
                    <div class="stat-label">Certificados Emitidos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $eventos_con_participantes; ?></div>
                    <div class="stat-label">Eventos con Participantes</div>
                </div>
            </div>
            
            <?php if (!empty($eventos_recientes)): ?>
            <div>
                <h2 class="section-title">Próximos Eventos</h2>
                <div class="features-grid">
                    <?php foreach ($eventos_recientes as $evento): ?>
                    <div class="feature-card">
                        <div class="feature-icon">📅</div>
                        <div class="feature-title"><?php echo htmlspecialchars($evento['nombre']); ?></div>
                        <div class="feature-description">
                            <strong>Organiza:</strong> <?php echo htmlspecialchars($evento['entidad_organizadora']); ?><br>
                            <strong>Fechas:</strong> <?php echo formatearFecha($evento['fecha_inicio']); ?> al <?php echo formatearFecha($evento['fecha_fin']); ?><br>
                            <strong>Modalidad:</strong> <?php echo ucfirst($evento['modalidad']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <h2 class="section-title">¿Por qué elegir nuestro sistema?</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">🔒</div>
                    <div class="feature-title">Seguridad Garantizada</div>
                    <div class="feature-description">
                        Cada certificado incluye un código de verificación único y hash de validación para garantizar su autenticidad y prevenir falsificaciones.
                    </div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">⚡</div>
                    <div class="feature-title">Generación Rápida</div>
                    <div class="feature-description">
                        Carga masiva de participantes y generación automática de certificados en minutos. Ideal para eventos con cientos de participantes.
                    </div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🎨</div>
                    <div class="feature-title">Diseños Personalizables</div>
                    <div class="feature-description">
                        Crea plantillas personalizadas con tu branding. Soporte para diferentes roles y tipos de certificados por evento.
                    </div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🌐</div>
                    <div class="feature-title">Consulta Pública</div>
                    <div class="feature-description">
                        Los participantes pueden consultar y descargar sus certificados en cualquier momento usando solo su número de identificación.
                    </div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <div class="feature-title">Panel de Control</div>
                    <div class="feature-description">
                        Dashboard completo con estadísticas, gestión de eventos, participantes y auditoría de todas las acciones del sistema.
                    </div>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">✅</div>
                    <div class="feature-title">Verificación Instantánea</div>
                    <div class="feature-description">
                        Cualquier persona puede verificar la autenticidad de un certificado ingresando el código de verificación.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>Sistema de Certificados</h3>
                    <p>Solución completa para la gestión de certificados digitales verificables.</p>
                </div>
                <div class="footer-section">
                    <h3>Enlaces Útiles</h3>
                    <p><a href="consulta.php">Consultar Certificado</a></p>
                    <p><a href="verificar.php">Verificar Certificado</a></p>
                    <p><a href="../admin/login.php">Acceso Administradores</a></p>
                </div>
                <div class="footer-section">
                    <h3>Contacto</h3>
                    <p>Para soporte técnico o consultas sobre el sistema, contacte al administrador.</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Sistema de Certificados Digitales. Desarrollado con PHP nativo.</p>
            </div>
        </div>
    </footer>
</body>
</html>