-- ============================================================
-- ASILO "CABEZA DE ALGODÓN" - Sistema de administración
-- Script DDL (24 tablas) + datos iniciales. MySQL / MariaDB (XAMPP)
-- Importar completo en phpMyAdmin: pestaña "Importar" > schema.sql
-- ADVERTENCIA: borra y vuelve a crear todas las tablas.
-- ============================================================

CREATE DATABASE IF NOT EXISTS asilo_cabeza_algodon
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE asilo_cabeza_algodon;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notificaciones_correo, bitacora_auditoria, gastos, donaciones,
                     pagos_fundacion, pagos_familiares, cargos_familiares,
                     recetas_visita, examenes_visita, examenes_catalogo, visitas_medicas,
                     solicitudes_medicas, evaluaciones_medicas, medicamentos_permanentes,
                     padecimientos, medicamentos, residentes, planes_servicio, habitaciones,
                     familiares, usuarios, especialidades, roles, configuracion;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- MÓDULO DE SEGURIDAD
-- ------------------------------------------------------------

-- 1. ROLES (actores del sistema)
CREATE TABLE roles (
    id_rol INT PRIMARY KEY,
    nombre_rol VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(200)
) ENGINE=InnoDB;

-- 2. ESPECIALIDADES MÉDICAS (de la fundación) con su tarifa de consulta
CREATE TABLE especialidades (
    id_especialidad INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    costo_consulta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activa TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- 3. USUARIOS (personal del asilo y de la fundación)
CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    id_rol INT NOT NULL,
    id_especialidad INT NULL,              -- solo médicos especialistas
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    telefono VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    ultimo_acceso DATETIME NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_roles FOREIGN KEY (id_rol)
        REFERENCES roles(id_rol) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_usuarios_especialidad FOREIGN KEY (id_especialidad)
        REFERENCES especialidades(id_especialidad) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 4. CONFIGURACIÓN GENERAL (descuento de la fundación, datos del asilo)
CREATE TABLE configuracion (
    clave VARCHAR(60) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL,
    descripcion VARCHAR(200)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MÓDULO DE INTERNOS Y ALOJAMIENTO
-- ------------------------------------------------------------

-- 5. FAMILIARES (responsable que paga la cuota y recibe los avisos)
CREATE TABLE familiares (
    id_familiar INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    parentesco VARCHAR(50) NOT NULL,
    documento_id VARCHAR(30) NOT NULL UNIQUE,
    direccion TEXT
) ENGINE=InnoDB;

-- 6. HABITACIONES
CREATE TABLE habitaciones (
    id_habitacion INT AUTO_INCREMENT PRIMARY KEY,
    numero_habitacion VARCHAR(10) NOT NULL UNIQUE,
    piso INT NOT NULL,
    tipo ENUM('Privada', 'Compartida', 'Cuidados Intensivos') DEFAULT 'Compartida',
    capacidad INT DEFAULT 2,
    estado ENUM('disponible', 'ocupada', 'mantenimiento') DEFAULT 'disponible',
    costo_adicional DECIMAL(10,2) DEFAULT 0.00
) ENGINE=InnoDB;

-- 7. PLANES DE SERVICIO (cuota mensual base)
CREATE TABLE planes_servicio (
    id_plan INT AUTO_INCREMENT PRIMARY KEY,
    nombre_plan VARCHAR(100) NOT NULL,
    costo_base DECIMAL(10,2) NOT NULL,
    descripcion TEXT
) ENGINE=InnoDB;

-- 8. RESIDENTES (internos del asilo)
CREATE TABLE residentes (
    id_residente INT AUTO_INCREMENT PRIMARY KEY,
    id_familiar INT NOT NULL,
    id_habitacion INT NULL,
    id_plan INT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    documento_id VARCHAR(30) NULL UNIQUE,
    fecha_nacimiento DATE NOT NULL,
    genero ENUM('M', 'F', 'Otro') NOT NULL,
    fecha_ingreso DATE NOT NULL,
    motivo_ingreso TEXT,
    observaciones TEXT,
    estado ENUM('activo', 'inactivo', 'hospitalizado') DEFAULT 'activo',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_residentes_familiares FOREIGN KEY (id_familiar)
        REFERENCES familiares(id_familiar) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_residentes_habitaciones FOREIGN KEY (id_habitacion)
        REFERENCES habitaciones(id_habitacion) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_residentes_planes FOREIGN KEY (id_plan)
        REFERENCES planes_servicio(id_plan) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MÓDULO DE FICHA MÉDICA
-- ------------------------------------------------------------

-- 9. MEDICAMENTOS (catálogo de farmacia de la fundación, precio de lista)
CREATE TABLE medicamentos (
    id_medicamento INT AUTO_INCREMENT PRIMARY KEY,
    nombre_comercial VARCHAR(100) NOT NULL,
    componente_activo VARCHAR(100) NOT NULL,
    presentacion VARCHAR(60) NOT NULL,
    precio DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT DEFAULT 0,
    stock_minimo INT DEFAULT 5,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- 10. PADECIMIENTOS (psicopatologías, enfermedades, alergias de cada interno)
CREATE TABLE padecimientos (
    id_padecimiento INT AUTO_INCREMENT PRIMARY KEY,
    id_residente INT NOT NULL,
    tipo ENUM('Psicopatología', 'Enfermedad crónica', 'Alergia', 'Discapacidad', 'Otro') NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    fecha_diagnostico DATE NULL,
    estado ENUM('Activo', 'Controlado', 'Resuelto') DEFAULT 'Activo',
    notas TEXT,
    id_usuario INT NULL,
    registrado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_padec_residente FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_padec_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 11. MEDICAMENTOS PERMANENTES ("medicinas de cajón" que se aplican en el asilo)
CREATE TABLE medicamentos_permanentes (
    id_permanente INT AUTO_INCREMENT PRIMARY KEY,
    id_residente INT NOT NULL,
    id_medicamento INT NOT NULL,
    dosis VARCHAR(100) NOT NULL,
    frecuencia VARCHAR(100) NOT NULL,
    indicaciones TEXT,
    fecha_inicio DATE NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    id_usuario INT NULL,
    CONSTRAINT fk_perm_residente FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_perm_medicamento FOREIGN KEY (id_medicamento)
        REFERENCES medicamentos(id_medicamento) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_perm_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 12. EVALUACIONES MÉDICAS del médico general (IMC vía microservicio ms-imc)
CREATE TABLE evaluaciones_medicas (
    id_evaluacion INT AUTO_INCREMENT PRIMARY KEY,
    id_residente INT NOT NULL,
    id_usuario INT NOT NULL,
    fecha_evaluacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    peso_kg DECIMAL(5,2) NOT NULL,
    altura_m DECIMAL(3,2) NOT NULL,
    imc DECIMAL(4,2),
    categoria_imc VARCHAR(50),
    presion_sistolica INT NULL,
    presion_diastolica INT NULL,
    glucosa DECIMAL(5,2) NULL,
    ritmo_cardiaco INT NULL,
    diagnostico TEXT,
    nivel_alerta ENUM('Normal', 'Atencion', 'Urgente') DEFAULT 'Normal',
    origen_calculo ENUM('microservicio', 'local') DEFAULT 'microservicio',
    CONSTRAINT fk_evaluaciones_residentes FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_evaluaciones_usuarios FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MÓDULO DE ATENCIÓN MÉDICA (asilo + fundación)
-- ------------------------------------------------------------

-- 13. SOLICITUDES MÉDICAS (médico general → fundación)
CREATE TABLE solicitudes_medicas (
    id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
    id_residente INT NOT NULL,
    id_medico_general INT NOT NULL,          -- usuario que remite
    id_especialidad INT NOT NULL,
    id_enfermero INT NULL,                   -- acompañante asignado
    motivo TEXT NOT NULL,
    prioridad ENUM('Normal', 'Urgente') DEFAULT 'Normal',
    estado ENUM('Pendiente', 'Programada', 'Atendida', 'Cancelada') DEFAULT 'Pendiente',
    fecha_solicitud DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_medico_asignado INT NULL,             -- especialista asignado por la fundación
    fecha_programada DATETIME NULL,
    notas_fundacion TEXT,
    CONSTRAINT fk_sol_residente FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sol_medico_general FOREIGN KEY (id_medico_general)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_especialidad FOREIGN KEY (id_especialidad)
        REFERENCES especialidades(id_especialidad) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_sol_enfermero FOREIGN KEY (id_enfermero)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sol_medico_asignado FOREIGN KEY (id_medico_asignado)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 14. VISITAS MÉDICAS (la solicitud se convierte en visita al ser atendida)
CREATE TABLE visitas_medicas (
    id_visita INT AUTO_INCREMENT PRIMARY KEY,
    id_solicitud INT NOT NULL UNIQUE,
    id_residente INT NOT NULL,
    id_medico INT NOT NULL,
    fecha_visita DATETIME DEFAULT CURRENT_TIMESTAMP,
    motivo TEXT NOT NULL,
    diagnostico TEXT,
    observaciones TEXT,
    costo_consulta DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- precio de lista
    descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,      -- descuento de la fundación
    estado ENUM('En curso', 'Finalizada') DEFAULT 'En curso',
    CONSTRAINT fk_visita_solicitud FOREIGN KEY (id_solicitud)
        REFERENCES solicitudes_medicas(id_solicitud) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_visita_residente FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_visita_medico FOREIGN KEY (id_medico)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 15. CATÁLOGO DE EXÁMENES DE LABORATORIO
CREATE TABLE examenes_catalogo (
    id_examen INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL UNIQUE,
    precio DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- 16. EXÁMENES SOLICITADOS EN UNA VISITA (y sus resultados del laboratorio)
CREATE TABLE examenes_visita (
    id_examen_visita INT AUTO_INCREMENT PRIMARY KEY,
    id_visita INT NOT NULL,
    id_examen INT NOT NULL,
    indicaciones VARCHAR(255),
    precio DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    estado ENUM('Solicitado', 'Realizado') DEFAULT 'Solicitado',
    resultado TEXT,
    fecha_resultado DATETIME NULL,
    id_laboratorista INT NULL,
    CONSTRAINT fk_exv_visita FOREIGN KEY (id_visita)
        REFERENCES visitas_medicas(id_visita) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_exv_examen FOREIGN KEY (id_examen)
        REFERENCES examenes_catalogo(id_examen) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_exv_laboratorista FOREIGN KEY (id_laboratorista)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 17. MEDICAMENTOS RECETADOS EN UNA VISITA (y su entrega en farmacia)
CREATE TABLE recetas_visita (
    id_receta INT AUTO_INCREMENT PRIMARY KEY,
    id_visita INT NOT NULL,
    id_medicamento INT NOT NULL,
    cantidad INT NOT NULL,
    indicaciones VARCHAR(255) NOT NULL,       -- cómo tomarlo
    duracion_dias INT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    estado ENUM('Pendiente', 'Entregado') DEFAULT 'Pendiente',
    fecha_entrega DATETIME NULL,
    id_farmaceutico INT NULL,
    CONSTRAINT fk_rec_visita FOREIGN KEY (id_visita)
        REFERENCES visitas_medicas(id_visita) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rec_medicamento FOREIGN KEY (id_medicamento)
        REFERENCES medicamentos(id_medicamento) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rec_farmaceutico FOREIGN KEY (id_farmaceutico)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MÓDULO DE ENTRADAS, SALIDAS Y MANEJO DE CAJA
-- ------------------------------------------------------------

-- 18. CARGOS A LA CUENTA DEL FAMILIAR (cuotas, consultas, exámenes, medicamentos)
CREATE TABLE cargos_familiares (
    id_cargo INT AUTO_INCREMENT PRIMARY KEY,
    id_residente INT NOT NULL,
    tipo ENUM('Cuota mensual', 'Consulta', 'Examen', 'Medicamento', 'Otro') NOT NULL,
    concepto VARCHAR(255) NOT NULL,
    id_visita INT NULL,
    monto_bruto DECIMAL(10,2) NOT NULL,
    descuento DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monto DECIMAL(10,2) NOT NULL,                 -- monto neto a pagar
    periodo CHAR(7) NULL,                         -- AAAA-MM para cuotas mensuales
    fecha DATE NOT NULL,
    id_usuario INT NULL,
    CONSTRAINT fk_cargo_residente FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cargo_visita FOREIGN KEY (id_visita)
        REFERENCES visitas_medicas(id_visita) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_cargo_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uq_cuota_periodo (id_residente, tipo, periodo)
) ENGINE=InnoDB;

-- 19. PAGOS DE LOS FAMILIARES (abonos a la cuenta del residente)
CREATE TABLE pagos_familiares (
    id_pago INT AUTO_INCREMENT PRIMARY KEY,
    id_residente INT NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    fecha DATE NOT NULL,
    metodo ENUM('Efectivo', 'Depósito', 'Transferencia', 'Tarjeta', 'Cheque') NOT NULL,
    referencia VARCHAR(60),
    observaciones VARCHAR(255),
    id_usuario INT NULL,
    registrado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagofam_residente FOREIGN KEY (id_residente)
        REFERENCES residentes(id_residente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pagofam_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 20. PAGOS A LA FUNDACIÓN (por cada cita/visita; permite abonos parciales)
CREATE TABLE pagos_fundacion (
    id_pago_fundacion INT AUTO_INCREMENT PRIMARY KEY,
    id_visita INT NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    fecha DATE NOT NULL,
    metodo ENUM('Efectivo', 'Depósito', 'Transferencia', 'Cheque') NOT NULL,
    referencia VARCHAR(60),
    id_usuario INT NULL,
    registrado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagofund_visita FOREIGN KEY (id_visita)
        REFERENCES visitas_medicas(id_visita) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pagofund_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 21. DONACIONES
CREATE TABLE donaciones (
    id_donacion INT AUTO_INCREMENT PRIMARY KEY,
    tipo_donante ENUM('Empresa internacional', 'Empresa nacional', 'Gobierno', 'Particular') NOT NULL,
    donante VARCHAR(150) NOT NULL,
    tipo_donacion ENUM('Monetaria', 'En especie') DEFAULT 'Monetaria',
    monto DECIMAL(12,2) NOT NULL DEFAULT 0.00,     -- en especie: valor estimado
    descripcion VARCHAR(255),
    fecha DATE NOT NULL,
    comprobante VARCHAR(60),
    id_usuario INT NULL,
    CONSTRAINT fk_donacion_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 22. GASTOS DEL ASILO (luz, agua, alimentación...)
CREATE TABLE gastos (
    id_gasto INT AUTO_INCREMENT PRIMARY KEY,
    categoria ENUM('Energía eléctrica', 'Agua', 'Teléfono e internet', 'Alimentación', 'Personal',
                   'Mantenimiento', 'Insumos y limpieza', 'Otro') NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    fecha DATE NOT NULL,
    comprobante VARCHAR(60),
    id_usuario INT NULL,
    CONSTRAINT fk_gasto_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MÓDULO DE AUDITORÍA Y NOTIFICACIONES
-- ------------------------------------------------------------

-- 23. CORREOS ENVIADOS AL FAMILIAR
CREATE TABLE notificaciones_correo (
    id_notificacion INT AUTO_INCREMENT PRIMARY KEY,
    id_solicitud INT NULL,
    destinatario VARCHAR(100) NOT NULL,
    asunto VARCHAR(200) NOT NULL,
    cuerpo TEXT NOT NULL,
    estado ENUM('Enviado', 'Error', 'Sin correo') NOT NULL,
    detalle_error VARCHAR(500),
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_solicitud FOREIGN KEY (id_solicitud)
        REFERENCES solicitudes_medicas(id_solicitud) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 24. BITÁCORA DE AUDITORÍA
CREATE TABLE bitacora_auditoria (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NULL,
    accion VARCHAR(100) NOT NULL,
    tabla_afectada VARCHAR(50) NOT NULL,
    detalles TEXT,
    ip_origen VARCHAR(45) NULL,
    fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bitacora_usuarios FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- DATOS INICIALES
-- Contraseña de todos los usuarios de prueba: Asilo2026!
-- ============================================================

INSERT INTO roles (id_rol, nombre_rol, descripcion) VALUES
(1, 'Administrador', 'Control total del sistema, usuarios y auditoría'),
(2, 'Médico general', 'Evalúa a los internos, lleva la ficha médica y crea solicitudes al especialista'),
(3, 'Contador', 'Caja: cuotas, cobros a familiares, donaciones, gastos y pagos a la fundación'),
(4, 'Enfermero', 'Acompaña al interno a sus citas y consulta su información'),
(5, 'Fundación', 'Recibe las solicitudes, asigna médico especialista y horario'),
(6, 'Médico especialista', 'Atiende la visita médica: diagnóstico, exámenes y medicamentos'),
(7, 'Laboratorio', 'Realiza los exámenes y registra los resultados'),
(8, 'Farmacia', 'Entrega los medicamentos recetados');

INSERT INTO especialidades (nombre, costo_consulta) VALUES
('Medicina interna', 250.00),
('Cardiología', 350.00),
('Neurología', 400.00),
('Psiquiatría', 350.00),
('Geriatría', 300.00),
('Traumatología', 300.00),
('Oftalmología', 250.00);

INSERT INTO configuracion (clave, valor, descripcion) VALUES
('descuento_fundacion_pct', '30', 'Porcentaje de descuento que la fundación otorga a los internos del asilo'),
('nombre_asilo', 'Asilo de Ancianos Cabeza de Algodón', 'Nombre que aparece en reportes y correos'),
('telefono_asilo', '7872-0000', 'Teléfono de contacto para los familiares');

-- Usuarios (hash Bcrypt de "Asilo2026!")
INSERT INTO usuarios (id_rol, id_especialidad, nombre, email, telefono, password_hash) VALUES
(1, NULL, 'Fredy Vásquez',        'admin@asilo.com',       '5500-0001', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(2, NULL, 'Dra. Lucía Herrera',   'medico@asilo.com',      '5500-0002', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(3, NULL, 'Carlos Méndez',        'contador@asilo.com',    '5500-0003', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(4, NULL, 'Sofía Cux',            'enfermera@asilo.com',   '5500-0004', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(4, NULL, 'Marvin Tzoc',          'enfermero@asilo.com',   '5500-0005', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(5, NULL, 'Andrea Solís',         'fundacion@asilo.com',   '5500-0006', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(6, 2,    'Dr. Javier Ramírez',   'cardiologo@asilo.com',  '5500-0007', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(6, 4,    'Dra. Elena Morales',   'psiquiatra@asilo.com',  '5500-0008', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(6, 1,    'Dr. Óscar Barrios',    'internista@asilo.com',  '5500-0009', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(7, NULL, 'Lic. Paola Juárez',    'laboratorio@asilo.com', '5500-0010', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(8, NULL, 'Kevin Ordóñez',        'farmacia@asilo.com',    '5500-0011', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(6, 5,    'Dra. Marta Chávez',    'geriatra@asilo.com',    '5500-0012', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta'),
(6, 6,    'Dr. Luis Fuentes',     'traumatologo@asilo.com','5500-0013', '$2y$10$7Ar11/qsj14zKcia/kEpau1bIBYBNyOi2ix2HdDKBLBsYSvJZypta');

INSERT INTO habitaciones (numero_habitacion, piso, tipo, capacidad, estado, costo_adicional) VALUES
('101', 1, 'Privada',             1, 'disponible',    150.00),
('102', 1, 'Compartida',          2, 'disponible',      0.00),
('103', 1, 'Compartida',          2, 'disponible',      0.00),
('104', 1, 'Cuidados Intensivos', 1, 'disponible',    300.00),
('201', 2, 'Privada',             1, 'disponible',    150.00),
('202', 2, 'Compartida',          2, 'disponible',      0.00),
('203', 2, 'Compartida',          3, 'disponible',      0.00),
('204', 2, 'Privada',             1, 'mantenimiento', 150.00);

INSERT INTO planes_servicio (nombre_plan, costo_base, descripcion) VALUES
('Estancia Básica', 1500.00, 'Habitación compartida, alimentación y cuidados generales'),
('Estancia Integral', 2200.00, 'Habitación privada, cuidados médicos continuos y terapia física'),
('Cuidado Especializado', 3000.00, 'Atención de enfermería 24 horas y monitoreo clínico permanente');

INSERT INTO familiares (nombre, apellido, telefono, email, parentesco, documento_id, direccion) VALUES
('María', 'López Pérez', '5512-3487', 'maria.lopez@correo.com', 'Hija', '2456781230101', 'Barrio San Antonio, Mazatenango'),
('José', 'García Ramírez', '4478-9012', 'jose.garcia@correo.com', 'Hijo', '1987654320901', 'Zona 1, Cuyotenango'),
('Ana', 'Morales de León', '3021-6655', NULL, 'Sobrina', '3012457890101', 'Colonia Las Flores, Mazatenango');

INSERT INTO residentes (id_familiar, id_habitacion, id_plan, nombre, apellido, fecha_nacimiento, genero, fecha_ingreso, motivo_ingreso, observaciones) VALUES
(1, 1, 2, 'Rosa', 'Pérez Cifuentes', '1941-03-12', 'F', '2025-11-04', 'La familia no puede brindarle cuidado permanente; requiere control de presión arterial.', 'Usa lentes para leer'),
(2, 2, 1, 'Manuel', 'García Ixcot', '1938-08-25', 'M', '2026-01-15', 'Deterioro cognitivo leve y pérdida de peso; vivía solo.', NULL),
(2, 2, 1, 'Pedro', 'Ajú Tzul', '1945-12-02', 'M', '2026-02-20', 'Movilidad reducida tras fractura de cadera.', 'Usa andadera'),
(3, 6, 1, 'Carmen', 'de León Ruiz', '1950-05-30', 'F', '2026-06-10', 'Diabetes tipo 2 de difícil control en casa.', NULL);

UPDATE habitaciones SET estado = 'ocupada' WHERE id_habitacion IN (1, 2);

INSERT INTO medicamentos (nombre_comercial, componente_activo, presentacion, precio, stock, stock_minimo) VALUES
('Losartán 50 mg', 'Losartán potásico', 'Caja 30 tabletas', 85.00, 40, 10),
('Metformina 850 mg', 'Metformina', 'Caja 30 tabletas', 45.00, 35, 10),
('Acetaminofén 500 mg', 'Paracetamol', 'Caja 100 tabletas', 30.00, 60, 15),
('Omeprazol 20 mg', 'Omeprazol', 'Caja 14 cápsulas', 38.00, 25, 8),
('Sertralina 50 mg', 'Sertralina', 'Caja 30 tabletas', 120.00, 15, 5),
('Quetiapina 25 mg', 'Quetiapina', 'Caja 30 tabletas', 145.00, 12, 5),
('Atorvastatina 20 mg', 'Atorvastatina', 'Caja 30 tabletas', 95.00, 20, 6),
('Aspirina 100 mg', 'Ácido acetilsalicílico', 'Caja 30 tabletas', 25.00, 50, 10),
('Calcio + Vitamina D', 'Carbonato de calcio, colecalciferol', 'Frasco 60 tabletas', 70.00, 18, 6),
('Insulina NPH', 'Insulina humana isofánica', 'Vial 10 ml', 210.00, 8, 3),
('Donepezilo 5 mg', 'Donepezilo', 'Caja 28 tabletas', 260.00, 6, 3),
('Ensure (suplemento)', 'Fórmula nutricional', 'Lata 400 g', 115.00, 20, 6);

INSERT INTO padecimientos (id_residente, tipo, descripcion, fecha_diagnostico, estado, id_usuario) VALUES
(1, 'Enfermedad crónica', 'Hipertensión arterial', '2015-06-01', 'Controlado', 2),
(2, 'Psicopatología', 'Deterioro cognitivo leve', '2025-10-20', 'Activo', 2),
(2, 'Enfermedad crónica', 'Desnutrición leve', '2026-01-15', 'Activo', 2),
(3, 'Discapacidad', 'Movilidad reducida (secuela de fractura de cadera)', '2025-12-01', 'Activo', 2),
(4, 'Enfermedad crónica', 'Diabetes mellitus tipo 2', '2012-03-10', 'Activo', 2),
(4, 'Psicopatología', 'Trastorno depresivo leve', '2026-07-01', 'Activo', 2),
(4, 'Alergia', 'Penicilina', NULL, 'Activo', 2);

INSERT INTO medicamentos_permanentes (id_residente, id_medicamento, dosis, frecuencia, indicaciones, fecha_inicio, id_usuario) VALUES
(1, 1, '1 tableta', 'Cada 24 horas', 'Por la mañana, con agua', '2025-11-04', 2),
(1, 8, '1 tableta', 'Cada 24 horas', 'Después del almuerzo', '2025-11-04', 2),
(2, 12, '1 medida', 'Cada 12 horas', 'Entre comidas', '2026-01-20', 2),
(4, 2, '1 tableta', 'Cada 12 horas', 'Con desayuno y cena', '2026-06-10', 2),
(4, 5, '1 tableta', 'Cada 24 horas', 'Por la mañana', '2026-07-01', 2);

INSERT INTO evaluaciones_medicas (id_residente, id_usuario, fecha_evaluacion, peso_kg, altura_m, imc, categoria_imc, presion_sistolica, presion_diastolica, glucosa, ritmo_cardiaco, diagnostico, nivel_alerta) VALUES
(1, 2, '2026-09-28 09:15:00', 58.0, 1.52, 25.10, 'Peso normal (rango geriátrico)', 135, 85, 98, 74, 'Control de rutina, presión estable.', 'Normal'),
(2, 2, '2026-09-30 10:40:00', 52.5, 1.66, 19.05, 'Bajo peso / riesgo de desnutrición', 118, 76, 90, 70, 'Pérdida de peso en el último mes, continuar suplemento.', 'Atencion'),
(4, 2, '2026-10-02 08:30:00', 74.0, 1.55, 30.80, 'Obesidad', 150, 95, 210, 82, 'Glucosa elevada en ayunas; se remite a medicina interna.', 'Urgente');

INSERT INTO examenes_catalogo (nombre, precio) VALUES
('Hematología completa', 75.00),
('Glucosa en ayunas', 30.00),
('Hemoglobina glicosilada (HbA1c)', 120.00),
('Perfil lipídico', 110.00),
('Creatinina y nitrógeno de urea', 80.00),
('Examen general de orina', 40.00),
('Electrocardiograma', 150.00),
('Rayos X de tórax', 175.00),
('Perfil tiroideo (TSH, T4)', 160.00);

-- Flujo de atención de ejemplo (fechas relativas al día de la importación)
-- 1) Atendida hace 5 días: Rosa con cardiología
INSERT INTO solicitudes_medicas (id_residente, id_medico_general, id_especialidad, id_enfermero, motivo, prioridad, estado, fecha_solicitud, id_medico_asignado, fecha_programada) VALUES
(1, 2, 2, 4, 'Palpitaciones ocasionales y presión arterial en el límite alto pese al tratamiento.', 'Normal', 'Atendida', DATE_SUB(NOW(), INTERVAL 8 DAY), 7, DATE_SUB(NOW(), INTERVAL 5 DAY));
INSERT INTO visitas_medicas (id_solicitud, id_residente, id_medico, fecha_visita, motivo, diagnostico, observaciones, costo_consulta, descuento_pct, estado) VALUES
(1, 1, 7, DATE_SUB(NOW(), INTERVAL 5 DAY), 'Palpitaciones ocasionales y presión arterial en el límite alto pese al tratamiento.',
 'Hipertensión arterial estadio 1 con extrasístoles benignas. Dislipidemia probable.', 'Continuar losartán. Reducir sal. Control en un mes con resultados.', 350.00, 30.00, 'Finalizada');
INSERT INTO examenes_visita (id_visita, id_examen, indicaciones, precio, descuento_pct, estado, resultado, fecha_resultado, id_laboratorista) VALUES
(1, 7, NULL, 150.00, 30.00, 'Realizado', 'Ritmo sinusal, frecuencia 72 lpm. Extrasístoles ventriculares aisladas. Sin signos de isquemia.', DATE_SUB(NOW(), INTERVAL 4 DAY), 10),
(1, 4, 'En ayunas de 12 horas', 110.00, 30.00, 'Solicitado', NULL, NULL, NULL);
INSERT INTO recetas_visita (id_visita, id_medicamento, cantidad, indicaciones, duracion_dias, precio_unitario, descuento_pct, estado, fecha_entrega, id_farmaceutico) VALUES
(1, 7, 1, '1 tableta por la noche', 30, 95.00, 30.00, 'Entregado', DATE_SUB(NOW(), INTERVAL 5 DAY), 11),
(1, 8, 1, '1 tableta después del almuerzo', 30, 25.00, 30.00, 'Pendiente', NULL, NULL);
INSERT INTO cargos_familiares (id_residente, tipo, concepto, id_visita, monto_bruto, descuento, monto, fecha, id_usuario) VALUES
(1, 'Consulta', 'Consulta de Cardiología con Dr. Javier Ramírez', 1, 350.00, 105.00, 245.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 7),
(1, 'Examen', 'Electrocardiograma', 1, 150.00, 45.00, 105.00, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 10),
(1, 'Medicamento', 'Atorvastatina 20 mg x 1', 1, 95.00, 28.50, 66.50, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 11);

-- 2) Programada para mañana a las 10:00: Manuel con psiquiatría
INSERT INTO solicitudes_medicas (id_residente, id_medico_general, id_especialidad, id_enfermero, motivo, prioridad, estado, fecha_solicitud, id_medico_asignado, fecha_programada, notas_fundacion) VALUES
(2, 2, 4, 5, 'Desorientación por las tardes y episodios de agitación nocturna; descartar progresión del deterioro cognitivo.', 'Normal', 'Programada', DATE_SUB(NOW(), INTERVAL 2 DAY), 8, DATE_ADD(CURDATE(), INTERVAL 34 HOUR), 'Presentarse 15 minutos antes en la clínica 3.');

-- 3) Pendiente y urgente: Carmen con medicina interna (la fundación debe programarla)
INSERT INTO solicitudes_medicas (id_residente, id_medico_general, id_especialidad, id_enfermero, motivo, prioridad, estado, fecha_solicitud) VALUES
(4, 2, 1, 4, 'Glucosa en ayunas de 210 mg/dL y presión 150/95 en la última evaluación. Ajustar tratamiento de diabetes.', 'Urgente', 'Pendiente', DATE_SUB(NOW(), INTERVAL 3 HOUR));

INSERT INTO bitacora_auditoria (id_usuario, accion, tabla_afectada, detalles) VALUES
(NULL, 'INSTALACION', 'sistema', 'Base de datos creada con datos iniciales');
