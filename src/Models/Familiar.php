<?php
// src/Models/Familiar.php

require_once __DIR__ . '/../../config/database.php';

class Familiar {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function listar(): array {
        return $this->db->query(
            "SELECT a.*, (SELECT COUNT(*) FROM residentes r
                          WHERE r.id_familiar = a.id_familiar AND r.estado <> 'inactivo') AS residentes_a_cargo
             FROM familiares a ORDER BY a.apellido, a.nombre"
        )->fetchAll();
    }

    public function obtener(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM familiares WHERE id_familiar = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function existeDocumento(string $doc, ?int $excluir = null): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM familiares WHERE documento_id = ? AND id_familiar <> ?');
        $stmt->execute([$doc, $excluir ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function crear(array $d): int {
        $stmt = $this->db->prepare(
            'INSERT INTO familiares (nombre, apellido, telefono, email, parentesco, documento_id, direccion)
             VALUES (:nombre, :apellido, :telefono, :email, :parentesco, :documento_id, :direccion)'
        );
        $stmt->execute($this->params($d));
        return (int)$this->db->lastInsertId();
    }

    public function actualizar(int $id, array $d): void {
        $stmt = $this->db->prepare(
            'UPDATE familiares SET nombre = :nombre, apellido = :apellido, telefono = :telefono, email = :email,
                    parentesco = :parentesco, documento_id = :documento_id, direccion = :direccion
             WHERE id_familiar = :id'
        );
        $stmt->execute($this->params($d) + [':id' => $id]);
    }

    public function totalResidentes(int $id): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM residentes WHERE id_familiar = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    public function eliminar(int $id): void {
        $this->db->prepare('DELETE FROM familiares WHERE id_familiar = ?')->execute([$id]);
    }

    private function params(array $d): array {
        return [
            ':nombre' => $d['nombre'], ':apellido' => $d['apellido'], ':telefono' => $d['telefono'],
            ':email' => $d['email'], ':parentesco' => $d['parentesco'], ':documento_id' => $d['documento_id'],
            ':direccion' => $d['direccion'],
        ];
    }
}
