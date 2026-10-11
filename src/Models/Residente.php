<?php
// src/Models/Residente.php

require_once __DIR__ . '/../../config/database.php';

class Residente {
    public const ESTADOS = ['activo', 'inactivo', 'hospitalizado'];
    public const GENEROS = ['M', 'F', 'Otro'];

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function listar(?string $estado = null): array {
        $sql = "SELECT r.id_residente, r.nombre, r.apellido, r.documento_id, r.fecha_nacimiento, r.genero,
                       r.fecha_ingreso, r.estado, r.id_habitacion, r.id_familiar, r.id_plan,
                       (SELECT COUNT(*) FROM padecimientos pd WHERE pd.id_residente = r.id_residente AND pd.estado <> 'Resuelto') AS padecimientos_activos,
                       TIMESTAMPDIFF(YEAR, r.fecha_nacimiento, CURDATE()) AS edad,
                       h.numero_habitacion, h.tipo AS tipo_habitacion,
                       CONCAT(a.nombre, ' ', a.apellido) AS familiar_nombre, a.telefono AS familiar_telefono,
                       p.nombre_plan,
                       (SELECT e.nivel_alerta FROM evaluaciones_medicas e WHERE e.id_residente = r.id_residente
                        ORDER BY e.fecha_evaluacion DESC, e.id_evaluacion DESC LIMIT 1) AS ultima_alerta
                FROM residentes r
                JOIN familiares a ON a.id_familiar = r.id_familiar
                LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
                LEFT JOIN planes_servicio p ON p.id_plan = r.id_plan";
        $params = [];
        if ($estado) {
            $sql .= ' WHERE r.estado = ?';
            $params[] = $estado;
        }
        $sql .= ' ORDER BY r.apellido, r.nombre';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function obtener(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT r.*, TIMESTAMPDIFF(YEAR, r.fecha_nacimiento, CURDATE()) AS edad,
                    h.numero_habitacion, h.tipo AS tipo_habitacion, h.piso,
                    a.nombre AS familiar_nombre, a.apellido AS familiar_apellido, a.telefono AS familiar_telefono,
                    a.email AS familiar_email, a.parentesco AS familiar_parentesco,
                    p.nombre_plan, p.costo_base
             FROM residentes r
             JOIN familiares a ON a.id_familiar = r.id_familiar
             LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
             LEFT JOIN planes_servicio p ON p.id_plan = r.id_plan
             WHERE r.id_residente = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function existeDocumento(?string $doc, ?int $excluir = null): bool {
        if (!$doc) return false;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM residentes WHERE documento_id = ? AND id_residente <> ?');
        $stmt->execute([$doc, $excluir ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function crear(array $d): int {
        $stmt = $this->db->prepare(
            "INSERT INTO residentes (id_familiar, id_habitacion, id_plan, nombre, apellido, documento_id,
                                     fecha_nacimiento, genero, fecha_ingreso, motivo_ingreso, observaciones, estado)
             VALUES (:id_familiar, :id_habitacion, :id_plan, :nombre, :apellido, :documento_id,
                     :fecha_nacimiento, :genero, :fecha_ingreso, :motivo_ingreso, :observaciones, 'activo')"
        );
        $stmt->execute($this->params($d));
        return (int)$this->db->lastInsertId();
    }

    public function actualizar(int $id, array $d): void {
        $stmt = $this->db->prepare(
            'UPDATE residentes SET id_familiar = :id_familiar, id_habitacion = :id_habitacion, id_plan = :id_plan,
                    nombre = :nombre, apellido = :apellido, documento_id = :documento_id,
                    fecha_nacimiento = :fecha_nacimiento, genero = :genero, fecha_ingreso = :fecha_ingreso,
                    motivo_ingreso = :motivo_ingreso, observaciones = :observaciones
             WHERE id_residente = :id'
        );
        $stmt->execute($this->params($d) + [':id' => $id]);
    }

    public function cambiarEstado(int $id, string $estado, bool $liberarHabitacion): void {
        $sql = 'UPDATE residentes SET estado = ?' . ($liberarHabitacion ? ', id_habitacion = NULL' : '') . ' WHERE id_residente = ?';
        $this->db->prepare($sql)->execute([$estado, $id]);
    }

    public function contarPorEstado(): array {
        $filas = $this->db->query('SELECT estado, COUNT(*) AS total FROM residentes GROUP BY estado')->fetchAll();
        $out = ['activo' => 0, 'inactivo' => 0, 'hospitalizado' => 0];
        foreach ($filas as $f) $out[$f['estado']] = (int)$f['total'];
        return $out;
    }

    private function params(array $d): array {
        return [
            ':id_familiar' => $d['id_familiar'], ':id_habitacion' => $d['id_habitacion'], ':id_plan' => $d['id_plan'],
            ':nombre' => $d['nombre'], ':apellido' => $d['apellido'], ':documento_id' => $d['documento_id'],
            ':fecha_nacimiento' => $d['fecha_nacimiento'], ':genero' => $d['genero'],
            ':fecha_ingreso' => $d['fecha_ingreso'], ':motivo_ingreso' => $d['motivo_ingreso'], ':observaciones' => $d['observaciones'],
        ];
    }
}
