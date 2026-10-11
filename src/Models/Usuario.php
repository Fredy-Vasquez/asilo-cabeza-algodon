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
                    u.creado_en, r.nombre_rol, e.nombre AS especialidad
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

    public function crear(array $d): int {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (id_rol, id_especialidad, nombre, email, telefono, password_hash, estado) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$d['id_rol'], $d['id_especialidad'], $d['nombre'], $d['email'], $d['telefono'], password_hash($d['password'], PASSWORD_BCRYPT), $d['estado']]);
        return (int)$this->db->lastInsertId();
    }

    public function actualizar(int $id, array $d): void {
        $this->db->prepare('UPDATE usuarios SET id_rol = ?, id_especialidad = ?, nombre = ?, email = ?, telefono = ?, estado = ? WHERE id_usuario = ?')
            ->execute([$d['id_rol'], $d['id_especialidad'], $d['nombre'], $d['email'], $d['telefono'], $d['estado'], $id]);
        if (!empty($d['password'])) {
            $this->db->prepare('UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?')
                ->execute([password_hash($d['password'], PASSWORD_BCRYPT), $id]);
        }
    }
}
