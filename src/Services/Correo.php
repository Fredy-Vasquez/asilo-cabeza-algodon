<?php
// src/Services/Correo.php
// Envío de correos al familiar mediante SMTP de Gmail (PHPMailer).
// Cada intento queda registrado en la tabla notificaciones_correo, se haya enviado o no.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../Lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../Lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

class Correo {
    /** 'smtp' (puertos 587/465) o 'apps_script' (HTTPS, puerto 443: funciona aunque la red bloquee el correo) */
    public static function metodo(): string {
        return defined('CORREO_METODO') && CORREO_METODO === 'apps_script' ? 'apps_script' : 'smtp';
    }

    public static function configurado(): bool {
        if (!defined('CORREO_ACTIVO') || !CORREO_ACTIVO) return false;
        if (self::metodo() === 'apps_script') {
            return defined('CORREO_APPS_SCRIPT_URL') && str_starts_with(CORREO_APPS_SCRIPT_URL, 'https://script.google.com/')
                && defined('CORREO_APPS_SCRIPT_CLAVE') && CORREO_APPS_SCRIPT_CLAVE !== '' && !str_contains(CORREO_APPS_SCRIPT_CLAVE, 'cambie');
        }
        return defined('CORREO_USUARIO') && CORREO_USUARIO !== '' && !str_contains(CORREO_USUARIO, 'su.correo')
            && defined('CORREO_PASSWORD') && CORREO_PASSWORD !== '' && !str_contains(CORREO_PASSWORD, 'xxxx');
    }

    public static function estado(): array {
        if (!defined('CORREO_ORIGEN')) {
            return ['listo' => false, 'mensaje' => 'Falta el archivo config/correo.php. Cópielo desde config/correo.ejemplo.php y complete sus datos.'];
        }
        $origen = CORREO_ORIGEN;
        if (!defined('CORREO_ACTIVO') || !CORREO_ACTIVO) {
            return ['listo' => false, 'mensaje' => "El envío está desactivado (CORREO_ACTIVO = false en {$origen})."];
        }
        if (!self::configurado()) {
            return ['listo' => false, 'mensaje' => self::metodo() === 'apps_script'
                ? "Complete CORREO_APPS_SCRIPT_URL y CORREO_APPS_SCRIPT_CLAVE en {$origen}."
                : "Complete CORREO_USUARIO y CORREO_PASSWORD en {$origen}."];
        }
        return ['listo' => true, 'mensaje' => self::metodo() === 'apps_script'
            ? 'Enviando con Google Apps Script (HTTPS, puerto 443).'
            : 'Enviando desde ' . CORREO_USUARIO . ' por SMTP.'];
    }

    /**
     * Envía un correo y lo registra. Nunca lanza excepción: un fallo de correo
     * no debe impedir que se guarde la solicitud.
     * @return array{estado:string, detalle:?string}
     */
    public static function enviar(?string $para, string $asunto, string $html, ?int $idSolicitud = null): array {
        if (!$para || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return self::registrar($idSolicitud, $para ?: '(sin correo)', $asunto, $html, 'Sin correo', 'El destinatario no tiene correo electrónico registrado.');
        }
        $estado = self::estado();
        if (!$estado['listo']) {
            return self::registrar($idSolicitud, $para, $asunto, $html, 'Error', $estado['mensaje']);
        }

        if (self::metodo() === 'apps_script') {
            return self::enviarAppsScript($para, $asunto, $html, $idSolicitud);
        }
        if (!extension_loaded('openssl')) {
            return self::registrar($idSolicitud, $para, $asunto, $html, 'Error',
                'La extensión openssl de PHP está desactivada. En XAMPP: Config de Apache > PHP (php.ini), quite el ";" de "extension=openssl", guarde y reinicie Apache.');
        }

        // Se intenta primero 587 (STARTTLS) y luego 465 (SSL). Se usa la dirección IPv4 de Gmail
        // porque en muchas redes de Windows la conexión por IPv6 falla.
        $ipv4 = gethostbyname(CORREO_SMTP_HOST);
        $host = filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ipv4 : CORREO_SMTP_HOST;
        $intentos = [[(int)CORREO_SMTP_PUERTO, PHPMailer::ENCRYPTION_STARTTLS], [465, PHPMailer::ENCRYPTION_SMTPS]];
        if ((int)CORREO_SMTP_PUERTO === 465) $intentos = array_reverse($intentos);

        $errores = [];
        foreach ($intentos as [$puerto, $cifrado]) {
            $m = new PHPMailer(true);
            $traza = [];
            try {
                $m->setLanguage('es', __DIR__ . '/../Lib/PHPMailer/');
                $m->isSMTP();
                $m->Host = $host;
                $m->Port = $puerto;
                $m->SMTPSecure = $cifrado;
                $m->SMTPAuth = true;
                $m->SMTPOptions = ['ssl' => ['peer_name' => CORREO_SMTP_HOST, 'verify_peer' => true, 'verify_peer_name' => true]];
                $m->Username = CORREO_USUARIO;
                $m->Password = str_replace(' ', '', CORREO_PASSWORD);
                $m->Timeout = 8;
                $m->SMTPDebug = 1;
                $m->Debugoutput = function ($linea) use (&$traza) { $traza[] = trim($linea); };
                $m->CharSet = PHPMailer::CHARSET_UTF8;
                $m->setFrom(CORREO_USUARIO, CORREO_REMITENTE);
                $m->addAddress($para);
                $m->isHTML(true);
                $m->Subject = $asunto;
                $m->Body = self::plantilla($asunto, $html);
                $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html))));
                $m->send();
                return self::registrar($idSolicitud, $para, $asunto, $html, 'Enviado', null);
            } catch (Throwable $e) {
                $detalle = $m->ErrorInfo ?: $e->getMessage();
                $tecnico = trim(implode(' | ', array_slice(array_filter($traza), -2)));
                if (stripos($detalle, 'authenticate') !== false || stripos($detalle, 'autenticar') !== false
                    || stripos($tecnico, '535') !== false) {
                    // Si Gmail respondió, el puerto funciona: el problema es la contraseña; no tiene caso probar otro puerto
                    return self::registrar($idSolicitud, $para, $asunto, $html, 'Error',
                        'Gmail rechazó el usuario o la contraseña de aplicación. Revise CORREO_USUARIO y CORREO_PASSWORD en config/correo.php (la contraseña de 16 letras, no la normal).');
                }
                $errores[] = "puerto {$puerto}: " . ($tecnico ?: $detalle);
            }
        }
        return self::registrar($idSolicitud, $para, $asunto, $html, 'Error',
            'No se pudo conectar con Gmail (' . implode('; ', $errores) . '). Use «Diagnosticar conexión» en Configuración para ver la causa.');
    }

    /** Envío por HTTPS a un script de Google (Apps Script) que usa MailApp de la cuenta del asilo */
    private static function enviarAppsScript(string $para, string $asunto, string $html, ?int $idSolicitud): array {
        if (!function_exists('curl_init')) {
            return self::registrar($idSolicitud, $para, $asunto, $html, 'Error', 'La extensión cURL de PHP está desactivada.');
        }
        $payload = json_encode([
            'clave' => CORREO_APPS_SCRIPT_CLAVE, 'para' => $para, 'asunto' => $asunto,
            'html' => self::plantilla($asunto, $html),
            'texto' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html)))),
            'remitente' => defined('CORREO_REMITENTE') ? CORREO_REMITENTE : APP_NOMBRE,
        ]);
        $ch = curl_init(CORREO_APPS_SCRIPT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_FOLLOWLOCATION => true,   // Google responde con una redirección que trae el resultado
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        // XAMPP a veces no trae certificados raíz para HTTPS: se usa el paquete incluido (Mozilla, vía certifi)
        if (!ini_get('curl.cainfo') && is_file(__DIR__ . '/../Lib/cacert.pem')) {
            curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../Lib/cacert.pem');
        }
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            $detalle = "No se pudo contactar a Google Apps Script: {$err}";
            if (stripos($err, 'SSL certificate') !== false) $detalle .= '. Falta el archivo de certificados (curl.cainfo) en php.ini.';
            return self::registrar($idSolicitud, $para, $asunto, $html, 'Error', $detalle);
        }
        $j = json_decode($resp, true);
        if (!is_array($j)) {
            $pista = stripos($resp, '<html') !== false
                ? 'Google devolvió una página en lugar de una respuesta. Revise que la implementación sea "Aplicación web" con acceso "Cualquier usuario" y que copió la URL que termina en /exec.'
                : 'Respuesta inesperada de Apps Script.';
            return self::registrar($idSolicitud, $para, $asunto, $html, 'Error', "{$pista} (HTTP {$codigo})");
        }
        if (empty($j['ok'])) {
            return self::registrar($idSolicitud, $para, $asunto, $html, 'Error', 'Apps Script: ' . ($j['error'] ?? 'error desconocido'));
        }
        return self::registrar($idSolicitud, $para, $asunto, $html, 'Enviado', null);
    }

    /** Revisa paso a paso por qué no se puede llegar a Gmail desde este servidor */
    public static function diagnostico(): array {
        $p = [];
        $p[] = ['prueba' => 'Extensión openssl de PHP', 'ok' => extension_loaded('openssl'),
                'detalle' => extension_loaded('openssl') ? 'Activa (' . OPENSSL_VERSION_TEXT . ')' : 'Desactivada. En php.ini quite el ";" de "extension=openssl" y reinicie Apache.'];
        $a = @dns_get_record(CORREO_SMTP_HOST, DNS_A) ?: [];
        $ipv4 = $a[0]['ip'] ?? null;
        $p[] = ['prueba' => 'Resolver smtp.gmail.com (IPv4)', 'ok' => (bool)$ipv4,
                'detalle' => $ipv4 ? "Dirección {$ipv4}" : 'No se pudo resolver el nombre. Revise la conexión a internet o el DNS.'];
        foreach ([587, 465] as $puerto) {
            $ini = microtime(true);
            $sock = $ipv4 ? @fsockopen($ipv4, $puerto, $errno, $errstr, 6) : false;
            $ms = round((microtime(true) - $ini) * 1000);
            $saludo = '';
            if ($sock) {
                stream_set_timeout($sock, 4);
                if ($puerto === 587) $saludo = trim((string)fgets($sock, 512));
                fclose($sock);
            }
            $p[] = ['prueba' => "Conexión al puerto {$puerto}", 'ok' => (bool)$sock,
                    'detalle' => $sock ? "Abierto ({$ms} ms)" . ($saludo ? ". Gmail responde: " . mb_substr($saludo, 0, 60) : '')
                                       : "Bloqueado: {$errstr} (código {$errno}, {$ms} ms). Lo bloquea el antivirus, el firewall o la red."];
        }
        $ini = microtime(true);
        $sock = $ipv4 ? @fsockopen('ssl://script.google.com', 443, $errno, $errstr, 6) : false;
        $ms = round((microtime(true) - $ini) * 1000);
        if ($sock) fclose($sock);
        $p[] = ['prueba' => 'Conexión HTTPS (puerto 443) para Apps Script', 'ok' => (bool)$sock,
                'detalle' => $sock ? "Abierto ({$ms} ms). Si los puertos de correo están bloqueados, use CORREO_METODO = 'apps_script'." : "Bloqueado: {$errstr} (código {$errno})."];
        $cfg = self::estado();
        $p[] = ['prueba' => 'Configuración del correo (método: ' . self::metodo() . ')', 'ok' => $cfg['listo'], 'detalle' => $cfg['mensaje']];
        return $p;
    }

    public static function listar(int $limite = 100): array {
        return Database::conn()->query(
            'SELECT n.*, r.nombre AS residente_nombre, r.apellido AS residente_apellido
             FROM notificaciones_correo n
             LEFT JOIN solicitudes_medicas s ON s.id_solicitud = n.id_solicitud
             LEFT JOIN residentes r ON r.id_residente = s.id_residente
             ORDER BY n.id_notificacion DESC LIMIT ' . max(1, min($limite, 500))
        )->fetchAll();
    }

    private static function registrar(?int $idSolicitud, string $para, string $asunto, string $html, string $estado, ?string $detalle): array {
        try {
            Database::conn()->prepare(
                'INSERT INTO notificaciones_correo (id_solicitud, destinatario, asunto, cuerpo, estado, detalle_error)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$idSolicitud, mb_substr($para, 0, 100), mb_substr($asunto, 0, 200), $html, $estado, $detalle ? mb_substr($detalle, 0, 500) : null]);
        } catch (Throwable $e) {
            error_log('Correo (registro): ' . $e->getMessage());
        }
        return ['estado' => $estado, 'detalle' => $detalle];
    }

    /** Diseño del correo: sencillo, legible en el celular y con la marca del asilo */
    private static function plantilla(string $titulo, string $contenido): string {
        $nombre = htmlspecialchars(APP_NOMBRE);
        $t = htmlspecialchars($titulo);
        return <<<HTML
<!DOCTYPE html><html lang="es"><body style="margin:0;background:#f3f3f4;font-family:Arial,Helvetica,sans-serif;color:#1b2622">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f3f4;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dce3e0;border-radius:10px">
<tr><td style="background:#14213D;color:#ffffff;padding:18px 24px;border-radius:10px 10px 0 0;border-bottom:4px solid #FCA311;font-size:18px;font-weight:bold">{$nombre}</td></tr>
<tr><td style="padding:24px;font-size:15px;line-height:1.55">
<h1 style="font-size:19px;margin:0 0 14px">{$t}</h1>
{$contenido}
</td></tr>
<tr><td style="padding:14px 24px;border-top:1px solid #dce3e0;font-size:12px;color:#5d6d67">Mensaje automático del sistema de administración del asilo. Para cualquier consulta comuníquese con la administración.</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
