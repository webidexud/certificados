-- ===============================================
-- BASE DE DATOS: Sistema de Certificados Digitales IDEXUD
-- Versión completa con todas las tablas, índices, vistas y procedimientos
-- ===============================================

-- Crear base de datos
CREATE DATABASE IF NOT EXISTS certificados_idexud 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE certificados_idexud;

-- ===============================================
-- 1. TABLA DE USUARIOS
-- ===============================================
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    rol ENUM('admin', 'usuario') DEFAULT 'usuario',
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    ultimo_acceso TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_estado (estado)
) ENGINE=InnoDB;

-- ===============================================
-- 2. TABLA DE EVENTOS
-- ===============================================
CREATE TABLE eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    modalidad ENUM('presencial', 'virtual', 'hibrida') NOT NULL DEFAULT 'presencial',
    entidad_organizadora VARCHAR(255) NOT NULL,
    lugar VARCHAR(255),
    horas_duracion INT UNSIGNED,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_nombre (nombre),
    INDEX idx_fechas (fecha_inicio, fecha_fin),
    INDEX idx_estado (estado),
    INDEX idx_modalidad (modalidad),
    INDEX idx_entidad (entidad_organizadora)
) ENGINE=InnoDB;

-- ===============================================
-- 3. TABLA DE PARTICIPANTES
-- ===============================================
CREATE TABLE participantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id INT NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    numero_identificacion VARCHAR(50) NOT NULL,
    correo_electronico VARCHAR(100) NOT NULL,
    rol VARCHAR(50) NOT NULL DEFAULT 'Participante',
    telefono VARCHAR(20),
    institucion VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participante_evento (evento_id, numero_identificacion),
    
    INDEX idx_evento (evento_id),
    INDEX idx_identificacion (numero_identificacion),
    INDEX idx_correo (correo_electronico),
    INDEX idx_rol (rol),
    INDEX idx_nombres (nombres, apellidos),
    INDEX idx_institucion (institucion)
) ENGINE=InnoDB;

-- ===============================================
-- 4. TABLA DE PLANTILLAS DE CERTIFICADOS (SVG)
-- ===============================================
CREATE TABLE plantillas_certificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id INT NOT NULL,
    rol VARCHAR(50) NOT NULL DEFAULT 'General',
    nombre_plantilla VARCHAR(255) NOT NULL DEFAULT 'Plantilla Sin Nombre',
    archivo_plantilla VARCHAR(255) NOT NULL,
    variables_disponibles JSON,
    ancho INT UNSIGNED DEFAULT 842,
    alto INT UNSIGNED DEFAULT 595,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_evento_rol (evento_id, rol),
    
    INDEX idx_evento_rol (evento_id, rol),
    INDEX idx_archivo (archivo_plantilla),
    INDEX idx_created (created_at),
    INDEX idx_dimensiones (ancho, alto)
) ENGINE=InnoDB;

-- ===============================================
-- 5. TABLA DE CERTIFICADOS
-- ===============================================
CREATE TABLE certificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participante_id INT NOT NULL,
    evento_id INT NOT NULL,
    codigo_verificacion VARCHAR(20) NOT NULL UNIQUE,
    archivo_pdf VARCHAR(255) NOT NULL,
    hash_validacion VARCHAR(64) NOT NULL,
    tipo_archivo ENUM('pdf', 'svg', 'html') DEFAULT 'pdf',
    dimensiones JSON,
    archivo_pdf_backup VARCHAR(255),
    estado ENUM('generado', 'descargado', 'verificado') DEFAULT 'generado',
    descargas INT UNSIGNED DEFAULT 0,
    fecha_generacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_descarga TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE CASCADE,
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE,
    
    INDEX idx_codigo (codigo_verificacion),
    INDEX idx_participante (participante_id),
    INDEX idx_evento (evento_id),
    INDEX idx_tipo (tipo_archivo),
    INDEX idx_fecha_generacion (fecha_generacion),
    INDEX idx_estado (estado),
    INDEX idx_hash (hash_validacion)
) ENGINE=InnoDB;

-- ===============================================
-- 6. TABLA DE AUDITORÍA
-- ===============================================
CREATE TABLE auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(100),
    accion VARCHAR(50) NOT NULL,
    tabla_afectada VARCHAR(50) NOT NULL,
    registro_id INT,
    datos_anteriores JSON,
    datos_nuevos JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_usuario (usuario),
    INDEX idx_accion (accion),
    INDEX idx_tabla (tabla_afectada),
    INDEX idx_registro (registro_id),
    INDEX idx_fecha (created_at),
    INDEX idx_ip (ip_address)
) ENGINE=InnoDB;

-- ===============================================
-- 7. TABLA DE VERSIONES DE PLANTILLAS
-- ===============================================
CREATE TABLE versiones_plantillas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plantilla_id INT NOT NULL,
    version INT NOT NULL DEFAULT 1,
    archivo_plantilla VARCHAR(255) NOT NULL,
    variables_disponibles JSON,
    notas_version TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (plantilla_id) REFERENCES plantillas_certificados(id) ON DELETE CASCADE,
    UNIQUE KEY unique_plantilla_version (plantilla_id, version),
    
    INDEX idx_plantilla (plantilla_id),
    INDEX idx_version (version),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ===============================================
-- 8. ÍNDICES ADICIONALES PARA RENDIMIENTO
-- ===============================================

-- Índices compuestos para consultas complejas
CREATE INDEX idx_participantes_evento_rol ON participantes(evento_id, rol);
CREATE INDEX idx_certificados_participante_evento ON certificados(participante_id, evento_id);
CREATE INDEX idx_certificados_codigo_hash ON certificados(codigo_verificacion, hash_validacion);
CREATE INDEX idx_auditoria_usuario_fecha ON auditoria(usuario, created_at);
CREATE INDEX idx_auditoria_tabla_registro ON auditoria(tabla_afectada, registro_id);

-- ===============================================
-- 9. VISTAS PARA CONSULTAS OPTIMIZADAS
-- ===============================================

-- Vista de plantillas completa con información del evento
CREATE OR REPLACE VIEW vista_plantillas_completa AS
SELECT 
    p.*,
    e.nombre as evento_nombre,
    e.fecha_inicio,
    e.fecha_fin,
    e.entidad_organizadora,
    e.modalidad,
    (SELECT COUNT(*) FROM participantes part 
     WHERE part.evento_id = e.id AND part.rol = p.rol) as participantes_rol,
    (SELECT COUNT(*) FROM certificados c 
     JOIN participantes part ON c.participante_id = part.id 
     WHERE part.evento_id = e.id AND part.rol = p.rol) as certificados_generados,
    (SELECT COUNT(*) FROM certificados c 
     JOIN participantes part ON c.participante_id = part.id 
     WHERE part.evento_id = e.id AND part.rol = p.rol 
     AND c.tipo_archivo = 'svg') as certificados_svg
FROM plantillas_certificados p
JOIN eventos e ON p.evento_id = e.id;

-- Vista de estadísticas de certificados por evento
CREATE OR REPLACE VIEW vista_estadisticas_certificados AS
SELECT 
    e.id as evento_id,
    e.nombre as evento_nombre,
    COUNT(DISTINCT p.id) as total_participantes,
    COUNT(DISTINCT c.id) as total_certificados,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'svg' THEN c.id END) as certificados_svg,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'pdf' THEN c.id END) as certificados_pdf,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'html' THEN c.id END) as certificados_html,
    COUNT(DISTINCT CASE WHEN c.id IS NULL THEN p.id END) as sin_certificado,
    ROUND((COUNT(DISTINCT c.id) / NULLIF(COUNT(DISTINCT p.id), 0)) * 100, 2) as porcentaje_completado,
    MAX(c.fecha_generacion) as ultimo_certificado_generado
FROM eventos e
LEFT JOIN participantes p ON e.id = p.evento_id
LEFT JOIN certificados c ON p.id = c.participante_id
GROUP BY e.id, e.nombre;

-- Vista de dashboard de plantillas SVG
CREATE OR REPLACE VIEW vista_dashboard_plantillas AS
SELECT 
    e.id as evento_id,
    e.nombre as evento_nombre,
    e.fecha_inicio,
    e.fecha_fin,
    e.estado as evento_estado,
    COUNT(DISTINCT p.id) as total_plantillas,
    COUNT(DISTINCT p.rol) as roles_con_plantilla,
    COUNT(DISTINCT part.id) as total_participantes,
    COUNT(DISTINCT c.id) as total_certificados,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'svg' THEN c.id END) as certificados_svg,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'pdf' THEN c.id END) as certificados_pdf,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'html' THEN c.id END) as certificados_html,
    ROUND(
        (COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'svg' THEN c.id END) / 
         NULLIF(COUNT(DISTINCT c.id), 0)) * 100, 1
    ) as porcentaje_svg,
    MAX(p.updated_at) as ultima_actualizacion_plantilla
FROM eventos e
LEFT JOIN plantillas_certificados p ON e.id = p.evento_id
LEFT JOIN participantes part ON e.id = part.evento_id
LEFT JOIN certificados c ON part.id = c.participante_id
GROUP BY e.id, e.nombre, e.fecha_inicio, e.fecha_fin, e.estado
ORDER BY e.fecha_inicio DESC;

-- ===============================================
-- 10. PROCEDIMIENTOS ALMACENADOS
-- ===============================================

DELIMITER //

-- Procedimiento para estadísticas de plantillas por evento
CREATE PROCEDURE EstadisticasPlantillasPorEvento(IN evento_id INT)
BEGIN
    SELECT 
        e.nombre as evento_nombre,
        COUNT(p.id) as total_plantillas,
        COUNT(DISTINCT p.rol) as roles_con_plantilla,
        GROUP_CONCAT(DISTINCT p.rol ORDER BY p.rol) as roles_disponibles,
        AVG(JSON_LENGTH(p.variables_disponibles)) as promedio_variables,
        MAX(p.created_at) as ultima_plantilla_creada,
        (
            SELECT GROUP_CONCAT(DISTINCT part.rol ORDER BY part.rol)
            FROM participantes part 
            LEFT JOIN plantillas_certificados pt ON part.evento_id = pt.evento_id AND part.rol = pt.rol
            WHERE part.evento_id = evento_id AND pt.id IS NULL
        ) as roles_sin_plantilla
    FROM eventos e
    LEFT JOIN plantillas_certificados p ON e.id = p.evento_id
    WHERE e.id = evento_id
    GROUP BY e.id, e.nombre;
END//

-- Procedimiento para limpiar archivos huérfanos
CREATE PROCEDURE LimpiarArchivosHuerfanos()
BEGIN
    DECLARE archivos_limpiados INT DEFAULT 0;
    
    -- Limpiar plantillas huérfanas
    DELETE p FROM plantillas_certificados p
    LEFT JOIN eventos e ON p.evento_id = e.id
    WHERE e.id IS NULL;
    
    SET archivos_limpiados = ROW_COUNT();
    
    -- Limpiar certificados huérfanos
    DELETE c FROM certificados c
    LEFT JOIN participantes p ON c.participante_id = p.id
    WHERE p.id IS NULL;
    
    SET archivos_limpiados = archivos_limpiados + ROW_COUNT();
    
    SELECT archivos_limpiados as archivos_limpiados;
END//

-- Procedimiento para migrar certificados existentes
CREATE PROCEDURE MigrarCertificadosExistentes()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE cert_id INT;
    DECLARE migrados INT DEFAULT 0;
    DECLARE cert_cursor CURSOR FOR 
        SELECT id FROM certificados WHERE tipo_archivo IS NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cert_cursor;
    read_loop: LOOP
        FETCH cert_cursor INTO cert_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Actualizar certificados existentes como PDF
        UPDATE certificados 
        SET tipo_archivo = 'pdf' 
        WHERE id = cert_id;
        
        SET migrados = migrados + 1;
        
    END LOOP;
    CLOSE cert_cursor;
    
    SELECT migrados as certificados_migrados;
END//

DELIMITER ;

-- ===============================================
-- 11. DATOS INICIALES
-- ===============================================

-- Crear usuario administrador por defecto
INSERT INTO usuarios (username, password, nombre, email, rol) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'admin@certificados.com', 'admin');
-- Contraseña por defecto: password

-- ===============================================
-- 12. VERIFICACIÓN FINAL
-- ===============================================

-- Mostrar resumen de creación
SELECT 'Base de datos certificados_idexud creada exitosamente' as estado, NOW() as fecha_creacion;

-- Mostrar todas las tablas creadas
SHOW TABLES;

-- Ejecutar migración de certificados existentes (si aplica)
CALL MigrarCertificadosExistentes();