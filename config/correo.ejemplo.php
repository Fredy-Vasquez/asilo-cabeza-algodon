<?php
// config/correo.ejemplo.php
// PLANTILLA. Copie este archivo como "correo.php" (en esta misma carpeta) y complete sus datos.
// El archivo correo.php NO se sube a GitHub (.gitignore): contiene datos privados.

define('CORREO_ACTIVO', false);                    // true para enviar correos reales
define('CORREO_REMITENTE', 'Asilo Cabeza de Algodón');

// Método de envío:
//   'smtp'        -> directo a Gmail por los puertos 587/465 (requiere que la red no los bloquee)
//   'apps_script' -> por HTTPS (puerto 443) mediante un script de Google. Funciona en casi cualquier red.
//                    Instrucciones en docs/apps-script/EnviarCorreo.gs
define('CORREO_METODO', 'smtp');

// --- Opción SMTP (contraseña de aplicación de Gmail) ---
//   Cuenta de Google > Seguridad > Verificación en 2 pasos > Contraseñas de aplicaciones.
define('CORREO_USUARIO', 'su.correo@gmail.com');
define('CORREO_PASSWORD', 'xxxx xxxx xxxx xxxx');  // las 16 letras (NO la contraseña normal)
define('CORREO_SMTP_HOST', 'smtp.gmail.com');
define('CORREO_SMTP_PUERTO', 587);

// --- Opción Apps Script ---
define('CORREO_APPS_SCRIPT_URL', 'https://script.google.com/macros/s/XXXXXXXX/exec');
define('CORREO_APPS_SCRIPT_CLAVE', 'cambie-esta-clave-por-una-frase-larga');  // la misma CLAVE del script
