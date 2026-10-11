<?php
// src/Core/Auth.php
// Autenticación con sesión PHP y control de acceso basado en roles (RBAC).

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Http.php';

class Auth {
    private const INACTIVIDAD_MAX = 60 * 60 * 2; // 2 horas

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
    }

    public static function login(string $email, string $password): array {
        $db = Database::conn();
        self::verificarVersionBD($db);
        $stmt = $db->prepare(
            'SELECT u.id_usuario, u.id_rol, u.nombre, u.email, u.password_hash, u.estado, r.nombre_rol
             FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $u = $stmt->fetch();

        // Mensaje genérico para no revelar si el correo existe
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Bitacora::registrar('LOGIN_FALLIDO', 'usuarios', 'Intento con correo: ' . $email, null);
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
        ];
        $db->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id_usuario = ?')->execute([$u['id_usuario']]);
        Bitacora::registrar('LOGIN', 'usuarios', 'Inicio de sesión exitoso');
        return $_SESSION['usuario'];
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
        $u = self::usuario();
        if (!$u) throw new HttpError('Su sesión expiró. Inicie sesión nuevamente.', 401);
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
