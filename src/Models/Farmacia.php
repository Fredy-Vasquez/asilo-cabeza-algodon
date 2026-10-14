<?php
// src/Models/Farmacia.php
// Recetas por entregar, entrega con descuento de inventario y catálogo de medicamentos.

require_once __DIR__ . '/../../config/database.php';

class Farmacia {
    public const ESTADOS = ['Pendiente', 'Entregado'];

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    private const SELECT = "SELECT rv.*, m.nombre_comercial, m.presentacion, m.stock, m.stock_minimo,
            v.fecha_visita, v.id_solicitud, v.id_residente,
            r.nombre AS residente_nombre, r.apellido AS residente_apellido, h.numero_habitacion,
            med.nombre AS medico, esp.nombre AS especialidad, f.nombre AS farmaceutico,
            ROUND(rv.precio_unitario * rv.cantidad, 2) AS total_bruto,
            ROUND(rv.precio_unitario * rv.cantidad * (1 - rv.descuento_pct / 100), 2) AS total_neto
        FROM recetas_visita rv
        JOIN medicamentos m ON m.id_medicamento = rv.id_medicamento
        JOIN visitas_medicas v ON v.id_visita = rv.id_visita
        JOIN residentes r ON r.id_residente = v.id_residente
        LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
        JOIN usuarios med ON med.id_usuario = v.id_medico
        JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
        JOIN especialidades esp ON esp.id_especialidad = s.id_especialidad
        LEFT JOIN usuarios f ON f.id_usuario = rv.id_farmaceutico";

    public function listar(?string $estado = null): array {
        $sql = self::SELECT . ($estado ? ' WHERE rv.estado = ?' : '') .
            " ORDER BY CASE rv.estado WHEN 'Pendiente' THEN 0 ELSE 1 END,
                       CASE WHEN rv.estado = 'Pendiente' THEN v.fecha_visita END ASC,
                       rv.fecha_entrega DESC
              LIMIT 300";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($estado ? [$estado] : []);
        return $stmt->fetchAll();
    }

    public function obtener(int $id, bool $bloquear = false): ?array {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE rv.id_receta = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function marcarEntregada(int $id, int $idUsuario): void {
        $this->db->prepare(
            "UPDATE recetas_visita SET estado = 'Entregado', fecha_entrega = NOW(), id_farmaceutico = ?
             WHERE id_receta = ? AND estado = 'Pendiente'"
        )->execute([$idUsuario, $id]);
    }

    public function contarPendientes(): int {
        return (int)$this->db->query("SELECT COUNT(*) FROM recetas_visita WHERE estado = 'Pendiente'")->fetchColumn();
    }

    /* ---------- Inventario ---------- */

    public function medicamentos(): array {
        return $this->db->query(
            "SELECT m.*, (SELECT COALESCE(SUM(rv.cantidad), 0) FROM recetas_visita rv
                          WHERE rv.id_medicamento = m.id_medicamento AND rv.estado = 'Pendiente') AS comprometido
             FROM medicamentos m ORDER BY m.activo DESC, m.nombre_comercial"
        )->fetchAll();
    }

    public function medicamento(int $id, bool $bloquear = false): ?array {
        $stmt = $this->db->prepare('SELECT * FROM medicamentos WHERE id_medicamento = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function stockBajo(): array {
        return $this->db->query(
            'SELECT id_medicamento, nombre_comercial, stock, stock_minimo FROM medicamentos
             WHERE activo = 1 AND stock <= stock_minimo ORDER BY stock - stock_minimo, nombre_comercial'
        )->fetchAll();
    }

    public function cambiarStock(int $id, int $delta): void {
        $this->db->prepare('UPDATE medicamentos SET stock = stock + ? WHERE id_medicamento = ?')->execute([$delta, $id]);
    }

    public function existeNombre(string $nombre, ?int $exceptoId = null): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM medicamentos WHERE nombre_comercial = ? AND id_medicamento <> ?');
        $stmt->execute([$nombre, $exceptoId ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function guardarMedicamento(?int $id, array $d): int {
        if ($id) {
            $this->db->prepare(
                'UPDATE medicamentos SET nombre_comercial = ?, componente_activo = ?, presentacion = ?, precio = ?, stock_minimo = ?, activo = ?
                 WHERE id_medicamento = ?'
            )->execute([$d['nombre_comercial'], $d['componente_activo'], $d['presentacion'], $d['precio'], $d['stock_minimo'], $d['activo'] ? 1 : 0, $id]);
            return $id;
        }
        $this->db->prepare(
            'INSERT INTO medicamentos (nombre_comercial, componente_activo, presentacion, precio, stock, stock_minimo, activo)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['nombre_comercial'], $d['componente_activo'], $d['presentacion'], $d['precio'], $d['stock'], $d['stock_minimo'], $d['activo'] ? 1 : 0]);
        return (int)$this->db->lastInsertId();
    }
}
