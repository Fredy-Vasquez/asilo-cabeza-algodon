<?php
// config/config.php
// Parámetros generales del sistema. Las variables de entorno tienen prioridad,
// lo que permite usar los mismos archivos en XAMPP (localhost) y en Docker.

define('APP_NOMBRE', 'Asilo Cabeza de Algodón');
define('APP_ZONA_HORARIA', 'America/Guatemala');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'asilo_cabeza_algodon');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// Microservicios Flask (en Docker Compose se usan los nombres de servicio)
define('MS_IMC_URL', getenv('MS_IMC_URL') ?: 'http://localhost:5001');
define('MS_COTIZADOR_URL', getenv('MS_COTIZADOR_URL') ?: 'http://localhost:5002');
define('MS_TIMEOUT', 3); // segundos (según Fase de Diseño, sección 2.4)

// Roles (coinciden con los id de la tabla roles)
define('ROL_ADMIN', 1);
define('ROL_MEDICO', 2);          // Médico general del asilo
define('ROL_CONTADOR', 3);        // Caja
define('ROL_ENFERMERO', 4);
define('ROL_FUNDACION', 5);
define('ROL_ESPECIALISTA', 6);    // Médico especialista de la fundación
define('ROL_LABORATORIO', 7);
define('ROL_FARMACIA', 8);

date_default_timezone_set(APP_ZONA_HORARIA);

// Correo (Gmail). Los datos privados viven en config/correo.php (XAMPP) o en variables
// de entorno del servidor (Docker/Azure, archivo .env). Ninguno de los dos se sube a GitHub.
if (file_exists(__DIR__ . '/correo.php')) {
    require_once __DIR__ . '/correo.php';
    define('CORREO_ORIGEN', 'config/correo.php');
} elseif (getenv('CORREO_METODO') !== false) {
    define('CORREO_ACTIVO', filter_var(getenv('CORREO_ACTIVO'), FILTER_VALIDATE_BOOLEAN));
    define('CORREO_REMITENTE', getenv('CORREO_REMITENTE') ?: 'Asilo Cabeza de Algodón');
    define('CORREO_METODO', getenv('CORREO_METODO') ?: 'apps_script');
    define('CORREO_USUARIO', getenv('CORREO_USUARIO') ?: '');
    define('CORREO_PASSWORD', getenv('CORREO_PASSWORD') ?: '');
    define('CORREO_SMTP_HOST', 'smtp.gmail.com');
    define('CORREO_SMTP_PUERTO', 587);
    define('CORREO_APPS_SCRIPT_URL', getenv('CORREO_APPS_SCRIPT_URL') ?: '');
    define('CORREO_APPS_SCRIPT_CLAVE', getenv('CORREO_APPS_SCRIPT_CLAVE') ?: '');
    define('CORREO_ORIGEN', 'el archivo .env del servidor');
}
