<?php
// src/Models/Usuario.php

require_once __DIR__ . '/../../config/database.php';

class Usuario {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function listar(): array {
        return $this->db->query(
            'SELECT u.id_usuario, u.id_rol, u.id_especialidad, u.nombre, u.email, u.telefono, u.estado, u.ultimo_acceso,
                    u.creado_en, u.debe_cambiar_clave, IF(u.bloqueado_hasta > NOW(), u.bloqueado_hasta, NULL) AS bloqueado_hasta,
                    r.nombre_rol, e.nombre AS especialidad
             FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
             LEFT JOIN especialidades e ON e.id_especialidad = u.id_especialidad
             ORDER BY u.id_rol, u.nombre'
        )->fetchAll();
    }

    public function roles(): array {
        return $this->db->query('SELECT id_rol, nombre_rol, descripcion FROM roles ORDER BY id_rol')->fetchAll();
    }

    /** Usuarios activos de un rol (p. ej. enfermeros o especialistas de una especialidad) */
    public function porRol(int $idRol, ?int $idEspecialidad = null): array {
        $sql = "SELECT u.id_usuario, u.nombre, u.email, u.telefono, u.id_especialidad, e.nombre AS especialidad
                FROM usuarios u LEFT JOIN especialidades e ON e.id_especialidad = u.id_especialidad
                WHERE u.id_rol = ? AND u.estado = 'activo'";
        $p = [$idRol];
        if ($idEspecialidad) { $sql .= ' AND u.id_especialidad = ?'; $p[] = $idEspecialidad; }
        $stmt = $this->db->prepare($sql . ' ORDER BY u.nombre');
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    public function existeEmail(string $email, ?int $excluir = null): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ? AND id_usuario <> ?');
        $stmt->execute([$email, $excluir ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function obtener(int $id): ?array {
        $stmt = $this->db->prepare('SELECT u.*, r.nombre_rol FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function porEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT u.id_usuario, u.nombre, u.email, u.estado, r.nombre_rol FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /** Los usuarios creados por el administrador deben cambiar la contraseña al primer ingreso */
    public function crear(array $d): int {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (id_rol, id_especialidad, nombre, email, telefono, password_hash, estado, debe_cambiar_clave) VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$d['id_rol'], $d['id_especialidad'], $d['nombre'], $d['email'], $d['telefono'], password_hash($d['password'], PASSWORD_BCRYPT), $d['estado']]);
        return (int)$this->db->lastInsertId();
    }

    /** $cerrarSesiones: el usuario pierde sus sesiones abiertas (cambio de estado, rol o contraseña) */
    public function actualizar(int $id, array $d, bool $cerrarSesiones = false): void {
        $this->db->prepare('UPDATE usuarios SET id_rol = ?, id_especialidad = ?, nombre = ?, email = ?, telefono = ?, estado = ?' .
            ($cerrarSesiones ? ', sesion_version = sesion_version + 1' : '') . ' WHERE id_usuario = ?')
            ->execute([$d['id_rol'], $d['id_especialidad'], $d['nombre'], $d['email'], $d['telefono'], $d['estado'], $id]);
    }

    public function hashClave(int $id): string {
        $stmt = $this->db->prepare('SELECT password_hash FROM usuarios WHERE id_usuario = ?');
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn();
    }

    /**
     * Cambia la contraseña, desbloquea la cuenta y cierra las sesiones abiertas.
     * $temporal = true: la asigna el administrador y el usuario deberá cambiarla al ingresar.
     * Devuelve la nueva versión de sesión.
     */
    public function cambiarClave(int $id, string $clave, bool $temporal): int {
        $this->db->prepare('UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = ?, intentos_fallidos = 0, bloqueado_hasta = NULL,
                            sesion_version = sesion_version + 1 WHERE id_usuario = ?')
            ->execute([password_hash($clave, PASSWORD_BCRYPT), $temporal ? 1 : 0, $id]);
        $this->atenderSolicitudesClave($id, $temporal ? ($_SESSION['usuario']['id'] ?? null) : null);
        $stmt = $this->db->prepare('SELECT sesion_version FROM usuarios WHERE id_usuario = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /* ---------- Solicitudes de "¿Olvidó su contraseña?" ---------- */

    public function solicitudClavePendiente(int $idUsuario): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM solicitudes_clave WHERE id_usuario = ? AND estado = 'Pendiente'");
        $stmt->execute([$idUsuario]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function crearSolicitudClave(int $idUsuario, ?string $ip): int {
        $this->db->prepare('INSERT INTO solicitudes_clave (id_usuario, ip) VALUES (?, ?)')->execute([$idUsuario, $ip ? mb_substr($ip, 0, 45) : null]);
        return (int)$this->db->lastInsertId();
    }

    public function solicitudesClavePendientes(): array {
        return $this->db->query(
            "SELECT s.id_solicitud_clave, s.id_usuario, s.creada_en, u.nombre, u.email, r.nombre_rol
             FROM solicitudes_clave s JOIN usuarios u ON u.id_usuario = s.id_usuario JOIN roles r ON r.id_rol = u.id_rol
             WHERE s.estado = 'Pendiente' ORDER BY s.creada_en"
        )->fetchAll();
    }

    public function contarSolicitudesClave(): int {
        return (int)$this->db->query("SELECT COUNT(*) FROM solicitudes_clave WHERE estado = 'Pendiente'")->fetchColumn();
    }

    /** Al cambiar la contraseña (por el admin o por el propio usuario) las solicitudes pendientes quedan atendidas */
    public function atenderSolicitudesClave(int $idUsuario, ?int $idAdmin): void {
        $this->db->prepare("UPDATE solicitudes_clave SET estado = 'Atendida', atendida_en = NOW(), atendida_por = ? WHERE id_usuario = ? AND estado = 'Pendiente'")
            ->execute([$idAdmin, $idUsuario]);
    }

    public function descartarSolicitudClave(int $id, int $idAdmin): bool {
        $stmt = $this->db->prepare("UPDATE solicitudes_clave SET estado = 'Descartada', atendida_en = NOW(), atendida_por = ? WHERE id_solicitud_clave = ? AND estado = 'Pendiente'");
        $stmt->execute([$idAdmin, $id]);
        return $stmt->rowCount() > 0;
    }
}
