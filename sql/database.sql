-- Crear base de datos
CREATE DATABASE IF NOT EXISTS certificados_digitales 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE certificados_digitales;

-- Tabla de usuarios administradores
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    rol ENUM('admin', 'operador') DEFAULT 'operador',
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de eventos
CREATE TABLE eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    modalidad ENUM('presencial', 'virtual', 'hibrida') NOT NULL,
    entidad_organizadora VARCHAR(255) NOT NULL,
    lugar VARCHAR(255),
    horas_duracion INT DEFAULT 0,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de plantillas de certificados
CREATE TABLE plantillas_certificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id INT NOT NULL,
    rol VARCHAR(100) NOT NULL,
    archivo_plantilla VARCHAR(255) NOT NULL,
    variables_disponibles JSON,
    ancho INT DEFAULT 842,
    alto INT DEFAULT 595,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE
);

-- Tabla de participantes
CREATE TABLE participantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id INT NOT NULL,
    nombres VARCHAR(255) NOT NULL,
    apellidos VARCHAR(255) NOT NULL,
    numero_identificacion VARCHAR(50) NOT NULL,
    correo_electronico VARCHAR(255) NOT NULL,
    rol VARCHAR(100) NOT NULL,
    telefono VARCHAR(20),
    institucion VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participante_evento (evento_id, numero_identificacion)
);

-- Tabla de certificados generados
CREATE TABLE certificados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participante_id INT NOT NULL,
    evento_id INT NOT NULL,
    codigo_verificacion VARCHAR(100) UNIQUE NOT NULL,
    archivo_pdf VARCHAR(255),
    hash_validacion VARCHAR(255),
    fecha_generacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_descarga TIMESTAMP NULL,
    estado ENUM('generado', 'enviado', 'descargado') DEFAULT 'generado',
    descargas INT DEFAULT 0,
    FOREIGN KEY (participante_id) REFERENCES participantes(id) ON DELETE CASCADE,
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE
);

-- Tabla de auditoría
CREATE TABLE auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(100),
    accion VARCHAR(255) NOT NULL,
    tabla_afectada VARCHAR(100),
    registro_id INT,
    datos_anteriores JSON,
    datos_nuevos JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para optimización
CREATE INDEX idx_participantes_identificacion ON participantes(numero_identificacion);
CREATE INDEX idx_participantes_email ON participantes(correo_electronico);
CREATE INDEX idx_certificados_codigo ON certificados(codigo_verificacion);
CREATE INDEX idx_certificados_participante ON certificados(participante_id);
CREATE INDEX idx_eventos_fecha ON eventos(fecha_inicio, fecha_fin);
CREATE INDEX idx_auditoria_fecha ON auditoria(created_at);

-- Insertar usuario administrador por defecto
INSERT INTO usuarios (username, password, nombre, email, rol) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'admin@certificados.com', 'admin');
-- Contraseña por defecto: "password" (cambiar después del primer login)