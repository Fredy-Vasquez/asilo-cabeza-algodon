<?php
// src/Controllers/CuentaController.php
// Cuenta propia del usuario: "¿Olvidó su contraseña?" (desde el login, sin sesión)
// y cambio de contraseña (voluntario u obligatorio tras una contraseña temporal).

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';
require_once __DIR__ . '/../Services/Correo.php';

class CuentaController {
    private const RESPUESTA_OLVIDO = 'Si el correo está registrado, el administrador recibirá su solicitud y le entregará una contraseña temporal. '
        . 'Al ingresar con ella, el sistema le pedirá crear una nueva.';

    /**
     * Pública. Responde siempre lo mismo, exista o no el correo (no revela qué cuentas existen).
     * Límite: 3 solicitudes cada 10 minutos por sesión y una pendiente por usuario.
     */
    public function olvideClave(array $in): array {
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError('Escriba un correo electrónico válido.', 422);

        $ahora = time();
        $_SESSION['olvido'] = array_values(array_filter($_SESSION['olvido'] ?? [], fn($t) => $t > $ahora - 600));
        if (count($_SESSION['olvido']) >= 3) {
            throw new HttpError('Ya envió varias solicitudes. Espere unos minutos o comuníquese directamente con la administración.', 429);
        }
        $_SESSION['olvido'][] = $ahora;

        $model = new Usuario();
        $u = $model->porEmail($email);
        if (!$u || $u['estado'] !== 'activo') {
            Bitacora::registrar('SOLICITUD_CLAVE_IGNORADA', 'usuarios', 'Correo no registrado o desactivado: ' . mb_substr($email, 0, 100), null);
            return ['mensaje' => self::RESPUESTA_OLVIDO];
        }
        if ($model->solicitudClavePendiente((int)$u['id_usuario'])) {
            return ['mensaje' => self::RESPUESTA_OLVIDO];
        }
        $id = $model->crearSolicitudClave((int)$u['id_usuario'], $_SERVER['REMOTE_ADDR'] ?? null);
        Bitacora::registrar('SOLICITUD_CLAVE', 'solicitudes_clave', ['id' => $id, 'usuario' => $u['email']], (int)$u['id_usuario']);

        // Aviso por correo a cada administrador activo (si el correo está configurado)
        $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
        foreach ($model->porRol(ROL_ADMIN) as $admin) {
            Correo::enviar($admin['email'], 'Solicitud de contraseña: ' . $u['nombre'],
                "<p>{$e($u['nombre'])} (<strong>{$e($u['email'])}</strong>, {$e($u['nombre_rol'])}) indicó en la pantalla de ingreso que olvidó su contraseña.</p>
                 <p>Para atenderla, ingrese al sistema y vaya a <strong>Administración &gt; Usuarios</strong>. Allí puede generar una contraseña temporal; al usarla, el sistema le pedirá crear una nueva.</p>
                 <p>Si usted no reconoce esta solicitud, puede descartarla desde la misma pantalla.</p>");
        }
        return ['mensaje' => self::RESPUESTA_OLVIDO];
    }

    /** Cambio de la propia contraseña. Exige la actual (o la temporal). */
    public function cambiarClave(array $in): array {
        $actual = (string)($in['actual'] ?? '');
        $nueva = (string)($in['nueva'] ?? '');
        $confirmar = (string)($in['confirmar'] ?? '');
        if ($actual === '' || $nueva === '') throw new HttpError('Escriba la contraseña actual y la nueva.', 422);
        if ($nueva !== $confirmar) throw new HttpError('La confirmación no coincide con la nueva contraseña.', 422);
        if (!self::segura($nueva)) throw new HttpError('La nueva contraseña debe tener al menos 8 caracteres, con letras y números.', 422);
        if ($nueva === $actual) throw new HttpError('La nueva contraseña debe ser diferente de la actual.', 422);

        $model = new Usuario();
        $id = Auth::id();
        if (!password_verify($actual, $model->hashClave($id))) {
            Bitacora::registrar('CAMBIO_CLAVE_FALLIDO', 'usuarios', 'Contraseña actual incorrecta');
            throw new HttpError('La contraseña actual no es correcta.', 422);
        }
        $version = $model->cambiarClave($id, $nueva, false);
        Auth::renovarSesion($version);
        Bitacora::registrar('CAMBIO_CLAVE', 'usuarios', 'El usuario cambió su contraseña');
        return ['mensaje' => 'Contraseña actualizada. Si tenía el sistema abierto en otro equipo, allí se cerró la sesión.'];
    }

    /** "Seguir conectado": cualquier petición renueva la sesión (Auth::iniciarSesion) */
    public function ping(): array {
        return ['ok' => true];
    }

    public static function segura(string $p): bool {
        return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
    }
}
