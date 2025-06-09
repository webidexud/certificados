-- sql/update_plantillas_svg.sql
-- Actualización completa para soporte SVG

-- 1. Actualizar tabla de plantillas para SVG
ALTER TABLE plantillas_certificados 
ADD COLUMN nombre_plantilla VARCHAR(255) NOT NULL DEFAULT 'Plantilla Sin Nombre' AFTER rol,
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER variables_disponibles,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 2. Actualizar tabla de certificados para soportar SVG
ALTER TABLE certificados 
ADD COLUMN tipo_archivo ENUM('pdf', 'svg') DEFAULT 'pdf' AFTER hash_validacion,
ADD COLUMN dimensiones JSON AFTER tipo_archivo,
ADD COLUMN archivo_pdf_backup VARCHAR(255) AFTER dimensiones;

-- 3. Índices para optimización
CREATE INDEX idx_plantillas_evento_rol ON plantillas_certificados(evento_id, rol);
CREATE INDEX idx_plantillas_created ON plantillas_certificados(created_at);
CREATE INDEX idx_certificados_tipo ON certificados(tipo_archivo);

-- 4. Crear tabla para versiones de plantillas
CREATE TABLE IF NOT EXISTS versiones_plantillas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plantilla_id INT NOT NULL,
    version INT NOT NULL DEFAULT 1,
    archivo_plantilla VARCHAR(255) NOT NULL,
    variables_disponibles JSON,
    notas_version TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plantilla_id) REFERENCES plantillas_certificados(id) ON DELETE CASCADE,
    UNIQUE KEY unique_plantilla_version (plantilla_id, version)
);

-- 5. Vista para obtener plantillas con información completa
CREATE OR REPLACE VIEW vista_plantillas_completa AS
SELECT 
    p.*,
    e.nombre as evento_nombre,
    e.fecha_inicio,
    e.fecha_fin,
    e.entidad_organizadora,
    e.modalidad,
    (SELECT COUNT(*) FROM participantes part WHERE part.evento_id = e.id AND part.rol = p.rol) as participantes_rol,
    (SELECT COUNT(*) FROM certificados c 
     JOIN participantes part ON c.participante_id = part.id 
     WHERE part.evento_id = e.id AND part.rol = p.rol) as certificados_generados,
    (SELECT COUNT(*) FROM certificados c 
     JOIN participantes part ON c.participante_id = part.id 
     WHERE part.evento_id = e.id AND part.rol = p.rol AND c.tipo_archivo = 'svg') as certificados_svg
FROM plantillas_certificados p
JOIN eventos e ON p.evento_id = e.id;

-- 6. Vista para estadísticas de certificados por tipo
CREATE OR REPLACE VIEW vista_estadisticas_certificados AS
SELECT 
    e.id as evento_id,
    e.nombre as evento_nombre,
    COUNT(DISTINCT p.id) as total_participantes,
    COUNT(DISTINCT c.id) as total_certificados,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'svg' THEN c.id END) as certificados_svg,
    COUNT(DISTINCT CASE WHEN c.tipo_archivo = 'pdf' THEN c.id END) as certificados_pdf,
    COUNT(DISTINCT CASE WHEN c.id IS NULL THEN p.id END) as sin_certificado,
    ROUND((COUNT(DISTINCT c.id) / COUNT(DISTINCT p.id)) * 100, 2) as porcentaje_completado
FROM eventos e
LEFT JOIN participantes p ON e.id = p.evento_id
LEFT JOIN certificados c ON p.id = c.participante_id
GROUP BY e.id, e.nombre;

-- 7. Trigger para crear versión automáticamente al actualizar plantilla
DELIMITER //
CREATE TRIGGER crear_version_plantilla_svg
AFTER UPDATE ON plantillas_certificados
FOR EACH ROW
BEGIN
    DECLARE max_version INT DEFAULT 0;
    
    -- Obtener la versión más alta
    SELECT COALESCE(MAX(version), 0) INTO max_version 
    FROM versiones_plantillas 
    WHERE plantilla_id = NEW.id;
    
    -- Insertar nueva versión solo si cambió el archivo
    IF OLD.archivo_plantilla != NEW.archivo_plantilla THEN
        INSERT INTO versiones_plantillas (
            plantilla_id, 
            version, 
            archivo_plantilla, 
            variables_disponibles,
            notas_version
        ) VALUES (
            NEW.id,
            max_version + 1,
            NEW.archivo_plantilla,
            NEW.variables_disponibles,
            CONCAT('Actualización automática SVG - ', NOW())
        );
    END IF;
END//
DELIMITER ;

-- 8. Procedimiento para limpiar archivos huérfanos
DELIMITER //
CREATE PROCEDURE LimpiarArchivosHuerfanos()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE archivo VARCHAR(255);
    DECLARE plantilla_cursor CURSOR FOR 
        SELECT p.archivo_plantilla 
        FROM plantillas_certificados p
        LEFT JOIN eventos e ON p.evento_id = e.id
        WHERE e.id IS NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Limpiar plantillas huérfanas
    DELETE p FROM plantillas_certificados p
    LEFT JOIN eventos e ON p.evento_id = e.id
    WHERE e.id IS NULL;
    
    -- Limpiar certificados huérfanos
    DELETE c FROM certificados c
    LEFT JOIN participantes p ON c.participante_id = p.id
    WHERE p.id IS NULL;
    
    SELECT ROW_COUNT() as archivos_limpiados;
END//
DELIMITER ;

-- 9. Función para obtener variables disponibles formateadas
DELIMITER //
CREATE FUNCTION FormatearVariablesSVG(variables_json JSON) 
RETURNS TEXT
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE resultado TEXT DEFAULT '';
    DECLARE i INT DEFAULT 0;
    DECLARE total INT;
    
    IF variables_json IS NULL THEN
        RETURN 'Sin variables definidas';
    END IF;
    
    SET total = JSON_LENGTH(variables_json);
    
    WHILE i < total DO
        SET resultado = CONCAT(
            resultado, 
            '{{', 
            JSON_UNQUOTE(JSON_EXTRACT(variables_json, CONCAT('$[', i, ']'))), 
            '}}'
        );
        IF i < total - 1 THEN
            SET resultado = CONCAT(resultado, ', ');
        END IF;
        SET i = i + 1;
    END WHILE;
    
    RETURN resultado;
END//
DELIMITER ;

-- 10. Procedimiento para migrar certificados PDF existentes
DELIMITER //
CREATE PROCEDURE MigrarCertificadosExistentes()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE cert_id INT;
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
        
    END LOOP;
    CLOSE cert_cursor;
    
    SELECT ROW_COUNT() as certificados_migrados;
END//
DELIMITER ;

-- 11. Procedimiento para obtener estadísticas de plantillas por evento
DELIMITER //
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
DELIMITER ;

-- 12. Trigger para validar dimensiones SVG
DELIMITER //
CREATE TRIGGER validar_dimensiones_svg
BEFORE INSERT ON plantillas_certificados
FOR EACH ROW
BEGIN
    -- Validar dimensiones mínimas y máximas
    IF NEW.ancho < 100 OR NEW.ancho > 5000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El ancho debe estar entre 100 y 5000 píxeles';
    END IF;
    
    IF NEW.alto < 100 OR NEW.alto > 5000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El alto debe estar entre 100 y 5000 píxeles';
    END IF;
    
    -- Validar que el archivo termine en .svg
    IF NOT (NEW.archivo_plantilla LIKE '%.svg') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El archivo debe ser de tipo SVG (.svg)';
    END IF;
END//
DELIMITER ;

-- 13. Función para contar certificados por tipo
DELIMITER //
CREATE FUNCTION ContarCertificadosPorTipo(evento_id INT, tipo VARCHAR(10))
RETURNS INT
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE total INT DEFAULT 0;
    
    SELECT COUNT(*) INTO total
    FROM certificados c
    JOIN participantes p ON c.participante_id = p.id
    WHERE p.evento_id = evento_id AND c.tipo_archivo = tipo;
    
    RETURN total;
END//
DELIMITER ;

-- 14. Vista para dashboard de plantillas SVG
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

-- 15. Insertar plantillas de ejemplo (opcionales)
-- Plantilla básica para eventos sin plantilla personalizada
INSERT IGNORE INTO plantillas_certificados (
    evento_id, 
    rol, 
    nombre_plantilla, 
    archivo_plantilla, 
    variables_disponibles,
    ancho,
    alto
) VALUES (
    0, -- ID especial para plantilla por defecto
    'General',
    'Plantilla SVG Por Defecto',
    'plantilla_default.svg',
    '["nombres", "apellidos", "evento_nombre", "codigo_verificacion", "fecha_inicio", "fecha_fin", "entidad_organizadora", "modalidad", "lugar", "horas_duracion", "rol", "fecha_generacion"]',
    842,
    595
);

-- 16. Procedimiento para backup de plantillas
DELIMITER //
CREATE PROCEDURE BackupPlantillas()
BEGIN
    DECLARE backup_date VARCHAR(20);
    SET backup_date = DATE_FORMAT(NOW(), '%Y%m%d_%H%i%s');
    
    -- Crear tabla de backup
    SET @sql = CONCAT('CREATE TABLE plantillas_backup_', backup_date, ' AS SELECT * FROM plantillas_certificados');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
    
    -- Crear tabla de backup para versiones
    SET @sql = CONCAT('CREATE TABLE versiones_plantillas_backup_', backup_date, ' AS SELECT * FROM versiones_plantillas');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
    
    SELECT CONCAT('Backup creado: plantillas_backup_', backup_date) as mensaje;
END//
DELIMITER ;

-- 17. Ejecutar migraciones necesarias
CALL MigrarCertificadosExistentes();

-- 18. Crear índices adicionales para rendimiento
CREATE INDEX idx_certificados_evento_tipo ON certificados(tipo_archivo);
CREATE INDEX idx_plantillas_archivo ON plantillas_certificados(archivo_plantilla);
CREATE INDEX idx_participantes_evento_rol ON participantes(evento_id, rol);

-- 19. Configurar charset para soporte completo de caracteres
ALTER TABLE plantillas_certificados CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE versiones_plantillas CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 20. Mensaje de finalización
SELECT 
    'Actualización completada para soporte SVG' as estado,
    NOW() as fecha_actualizacion,
    (SELECT COUNT(*) FROM plantillas_certificados) as total_plantillas,
    (SELECT COUNT(*) FROM certificados WHERE tipo_archivo = 'svg') as certificados_svg_existentes,
    'Sistema listo para generar certificados SVG' as mensaje;