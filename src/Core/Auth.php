<?php
// src/Core/Auth.php
// Autenticación con sesión PHP y control de acceso basado en roles (RBAC).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Migraciones.php';

class Auth {
    public const INACTIVIDAD_MAX = 60 * 60 * 2; // 2 horas
    public const INTENTOS_MAX = 5;               // intentos fallidos seguidos antes de bloquear
    public const MINUTOS_BLOQUEO = 15;

    public static function iniciarSesion(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('ASILO_SESION');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();

        if (isset($_SESSION['ultimo_movimiento']) && time() - $_SESSION['ultimo_movimiento'] > self::INACTIVIDAD_MAX) {
            self::cerrar();
            session_start();
        }
        $_SESSION['ultimo_movimiento'] = time();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        // Actualiza la base de datos si es de una versión anterior (una vez por sesión)
        if (($_SESSION['esquema'] ?? 0) !== Migraciones::VERSION) {
            try {
                Migraciones::aplicar(Database::conn());
                $_SESSION['esquema'] = Migraciones::VERSION;
            } catch (Throwable $e) {
                error_log('Migraciones: ' . $e->getMessage());
            }
        }
    }

    public static function login(string $email, string $password): array {
        $db = Database::conn();
        self::verificarVersionBD($db);
        $stmt = $db->prepare(
            'SELECT u.id_usuario, u.id_rol, u.nombre, u.email, u.password_hash, u.estado, u.debe_cambiar_clave,
                    u.intentos_fallidos, u.bloqueado_hasta, u.sesion_version, r.nombre_rol,
                    GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), u.bloqueado_hasta) / 60)) AS minutos_bloqueo
             FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $u = $stmt->fetch();

        // Cuenta bloqueada por intentos fallidos: ni siquiera se evalúa la contraseña
        if ($u && $u['bloqueado_hasta'] && (int)$u['minutos_bloqueo'] > 0) {
            Bitacora::registrar('LOGIN_BLOQUEADO', 'usuarios', 'Intento con la cuenta bloqueada: ' . $u['email'], (int)$u['id_usuario']);
            throw new HttpError(self::mensajeBloqueo((int)$u['minutos_bloqueo']), 429);
        }

        // Mensaje genérico para no revelar si el correo existe
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Bitacora::registrar('LOGIN_FALLIDO', 'usuarios', 'Intento con correo: ' . mb_substr($email, 0, 100), null);
            if ($u) {
                $intentos = (int)$u['intentos_fallidos'] + 1;
                if ($intentos >= self::INTENTOS_MAX) {
                    $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ' . self::MINUTOS_BLOQUEO . ' MINUTE) WHERE id_usuario = ?')
                       ->execute([$u['id_usuario']]);
                    Bitacora::registrar('CUENTA_BLOQUEADA', 'usuarios', ['email' => $u['email'], 'minutos' => self::MINUTOS_BLOQUEO], (int)$u['id_usuario']);
                    throw new HttpError(self::mensajeBloqueo(self::MINUTOS_BLOQUEO), 429);
                }
                $db->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id_usuario = ?')->execute([$intentos, $u['id_usuario']]);
                $quedan = self::INTENTOS_MAX - $intentos;
                if ($quedan <= 2) {
                    throw new HttpError("Correo o contraseña incorrectos. Le quedan {$quedan} intento(s) antes de que la cuenta se bloquee " . self::MINUTOS_BLOQUEO . ' minutos.', 401);
                }
            }
            throw new HttpError('Correo o contraseña incorrectos.', 401);
        }
        if ($u['estado'] !== 'activo') {
            throw new HttpError('Su usuario está desactivado. Comuníquese con el administrador.', 403);
        }

        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id'     => (int)$u['id_usuario'],
            'id_rol' => (int)$u['id_rol'],
            'rol'    => $u['nombre_rol'],
            'nombre' => $u['nombre'],
            'email'  => $u['email'],
            'sesion_version' => (int)$u['sesion_version'],
            'debe_cambiar'   => (bool)$u['debe_cambiar_clave'],
        ];
        $db->prepare('UPDATE usuarios SET ultimo_acceso = NOW(), intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?')->execute([$u['id_usuario']]);
        Bitacora::registrar('LOGIN', 'usuarios', $u['debe_cambiar_clave'] ? 'Inicio de sesión con contraseña temporal' : 'Inicio de sesión exitoso');
        return $_SESSION['usuario'];
    }

    private static function mensajeBloqueo(int $minutos): string {
        return "Por seguridad, la cuenta quedó bloqueada por demasiados intentos fallidos. Intente de nuevo en {$minutos} minuto(s) "
             . 'o use "¿Olvidó su contraseña?" para pedir una contraseña temporal al administrador.';
    }

    /**
     * Usuario de la sesión, verificado contra la base de datos en cada petición:
     * si el administrador lo desactivó, le cambió el rol o le restableció la contraseña,
     * la sesión se cierra (devuelve null y deja el motivo en $_SESSION['motivo_cierre']).
     */
    public static function vigente(): ?array {
        $u = self::usuario();
        if (!$u) return null;
        $stmt = Database::conn()->prepare('SELECT estado, sesion_version, debe_cambiar_clave FROM usuarios WHERE id_usuario = ?');
        $stmt->execute([$u['id']]);
        $f = $stmt->fetch();
        if (!$f || $f['estado'] !== 'activo' || !isset($u['sesion_version']) || (int)$f['sesion_version'] !== $u['sesion_version']) {
            self::cerrar();
            return null;
        }
        $_SESSION['usuario']['debe_cambiar'] = (bool)$f['debe_cambiar_clave'];
        return $_SESSION['usuario'];
    }

    /** Tras cambiar la propia contraseña: la sesión actual sigue válida, las demás se cierran */
    public static function renovarSesion(int $version): void {
        session_regenerate_id(true);
        $_SESSION['usuario']['sesion_version'] = $version;
        $_SESSION['usuario']['debe_cambiar'] = false;
    }

    /** Detecta una base creada con el schema.sql anterior (sin las columnas nuevas) */
    private static function verificarVersionBD(PDO $db): void {
        $faltan = (int)$db->query(
            "SELECT 2 - COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'id_especialidad')
                 OR (TABLE_NAME = 'residentes' AND COLUMN_NAME = 'motivo_ingreso'))"
        )->fetchColumn();
        if ($faltan > 0) {
            throw new HttpError('La base de datos es de la versión anterior del sistema. Importe database/schema.sql en phpMyAdmin y vuelva a intentar.', 503);
        }
    }

    public static function cerrar(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function usuario(): ?array {
        return $_SESSION['usuario'] ?? null;
    }

    public static function id(): ?int {
        return self::usuario()['id'] ?? null;
    }

    public static function tieneRol(array $roles): bool {
        $u = self::usuario();
        return $u !== null && in_array($u['id_rol'], $roles, true);
    }

    /** Lanza 401/403 si no hay sesión o el rol no está permitido. Lista vacía = cualquier rol. */
    public static function exigir(array $roles = []): array {
        $tenia = self::usuario() !== null;
        $u = self::vigente();
        if (!$u) {
            throw new HttpError($tenia ? 'Su sesión se cerró porque el administrador modificó su cuenta. Inicie sesión nuevamente.'
                                       : 'Su sesión expiró. Inicie sesión nuevamente.', 401);
        }
        if ($roles && !in_array($u['id_rol'], $roles, true)) {
            throw new HttpError('Su rol no tiene permiso para realizar esta acción.', 403);
        }
        return $u;
    }

    public static function csrf(): string {
        return $_SESSION['csrf'] ?? '';
    }

    public static function verificarCsrf(): void {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(self::csrf(), $token)) {
            throw new HttpError('La página está desactualizada. Recárguela e intente de nuevo.', 419);
        }
    }
}
