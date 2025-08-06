CREATE DATABASE IF NOT EXISTS certificados_idexud 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE certificados_idexud;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    rol ENUM('admin', 'usuario') DEFAULT 'usuario',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO usuarios (username, password, nombre, email, rol) VALUES 
('admin', '.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'admin@certificados.com', 'admin');

SELECT 'Database initialized successfully' as status;
