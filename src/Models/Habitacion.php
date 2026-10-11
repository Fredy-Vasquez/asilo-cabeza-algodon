<?php
// src/Models/Habitacion.php

require_once __DIR__ . '/../../config/database.php';

class Habitacion {
    public const TIPOS = ['Privada', 'Compartida', 'Cuidados Intensivos'];
    public const ESTADOS = ['disponible', 'ocupada', 'mantenimiento'];

    /** Residentes que ocupan cama (los hospitalizados conservan su lugar) */
    private const OCUPA_CAMA = "('activo','hospitalizado')";

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function listar(): array {
        $habs = $this->db->query(
            'SELECT h.*, (SELECT COUNT(*) FROM residentes r
                          WHERE r.id_habitacion = h.id_habitacion AND r.estado IN ' . self::OCUPA_CAMA . ') AS ocupantes
             FROM habitaciones h ORDER BY h.piso, h.numero_habitacion'
        )->fetchAll();

        $res = $this->db->query(
            'SELECT id_residente, id_habitacion, nombre, apellido, estado FROM residentes
             WHERE id_habitacion IS NOT NULL AND estado IN ' . self::OCUPA_CAMA . ' ORDER BY nombre'
        )->fetchAll();
        $porHab = [];
        foreach ($res as $r) $porHab[$r['id_habitacion']][] = $r;

        foreach ($habs as &$h) {
            $h['ocupantes'] = (int)$h['ocupantes'];
            $h['capacidad'] = (int)$h['capacidad'];
            $h['residentes'] = $porHab[$h['id_habitacion']] ?? [];
        }
        return $habs;
    }

    /** Habitaciones con al menos una cama libre */
    public function disponibles(?int $incluir = null): array {
        $stmt = $this->db->prepare(
            'SELECT h.id_habitacion, h.numero_habitacion, h.piso, h.tipo, h.capacidad, h.costo_adicional,
                    (SELECT COUNT(*) FROM residentes r WHERE r.id_habitacion = h.id_habitacion
                       AND r.estado IN ' . self::OCUPA_CAMA . ') AS ocupantes
             FROM habitaciones h
             WHERE h.estado <> \'mantenimiento\'
             ORDER BY h.piso, h.numero_habitacion'
        );
        $stmt->execute();
        return array_values(array_filter($stmt->fetchAll(), function ($h) use ($incluir) {
            return (int)$h['ocupantes'] < (int)$h['capacidad'] || (int)$h['id_habitacion'] === $incluir;
        }));
    }

    public function obtener(int $id, bool $bloquear = false): ?array {
        $sql = 'SELECT * FROM habitaciones WHERE id_habitacion = ?' . ($bloquear ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function ocupantes(int $id): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM residentes WHERE id_habitacion = ? AND estado IN ' . self::OCUPA_CAMA);
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /** Ajusta disponible/ocupada según ocupación real (no toca las que están en mantenimiento) */
    public function recalcularEstado(int $id): void {
        $h = $this->obtener($id);
        if (!$h || $h['estado'] === 'mantenimiento') return;
        $nuevo = $this->ocupantes($id) >= (int)$h['capacidad'] ? 'ocupada' : 'disponible';
        $this->db->prepare('UPDATE habitaciones SET estado = ? WHERE id_habitacion = ?')->execute([$nuevo, $id]);
    }

    public function existeNumero(string $numero, ?int $excluir = null): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM habitaciones WHERE numero_habitacion = ? AND id_habitacion <> ?');
        $stmt->execute([$numero, $excluir ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function crear(array $d): int {
        $stmt = $this->db->prepare(
            'INSERT INTO habitaciones (numero_habitacion, piso, tipo, capacidad, estado, costo_adicional)
             VALUES (:numero, :piso, :tipo, :capacidad, :estado, :costo)'
        );
        $stmt->execute([
            ':numero' => $d['numero_habitacion'], ':piso' => $d['piso'], ':tipo' => $d['tipo'],
            ':capacidad' => $d['capacidad'], ':estado' => $d['estado'], ':costo' => $d['costo_adicional'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function actualizar(int $id, array $d): void {
        $stmt = $this->db->prepare(
            'UPDATE habitaciones SET numero_habitacion = :numero, piso = :piso, tipo = :tipo,
                    capacidad = :capacidad, estado = :estado, costo_adicional = :costo
             WHERE id_habitacion = :id'
        );
        $stmt->execute([
            ':numero' => $d['numero_habitacion'], ':piso' => $d['piso'], ':tipo' => $d['tipo'],
            ':capacidad' => $d['capacidad'], ':estado' => $d['estado'], ':costo' => $d['costo_adicional'], ':id' => $id,
        ]);
    }

    public function eliminar(int $id): void {
        $this->db->prepare('DELETE FROM habitaciones WHERE id_habitacion = ?')->execute([$id]);
    }

    /** Costo adicional de referencia por tipo (para el cotizador) */
    public function costosPorTipo(): array {
        return $this->db->query(
            'SELECT tipo, MAX(costo_adicional) AS costo_adicional, COUNT(*) AS cantidad
             FROM habitaciones GROUP BY tipo ORDER BY costo_adicional'
        )->fetchAll();
    }
}
