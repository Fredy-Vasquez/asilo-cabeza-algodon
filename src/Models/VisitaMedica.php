<?php
// src/Models/VisitaMedica.php
// Visita médica: la solicitud convertida en consulta, con sus exámenes y medicamentos.

require_once __DIR__ . '/../../config/database.php';

class VisitaMedica {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function obtener(int $id, bool $bloquear = false): ?array {
        $stmt = $this->db->prepare(
            'SELECT v.*, u.nombre AS medico, e.nombre AS especialidad
             FROM visitas_medicas v
             JOIN usuarios u ON u.id_usuario = v.id_medico
             JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
             JOIN especialidades e ON e.id_especialidad = s.id_especialidad
             WHERE v.id_visita = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function porSolicitud(int $idSolicitud): ?array {
        $stmt = $this->db->prepare('SELECT id_visita FROM visitas_medicas WHERE id_solicitud = ?');
        $stmt->execute([$idSolicitud]);
        $id = $stmt->fetchColumn();
        return $id ? $this->completa((int)$id) : null;
    }

    /** Visita con exámenes y recetas */
    public function completa(int $id): ?array {
        $v = $this->obtener($id);
        if (!$v) return null;
        $v['examenes'] = $this->examenes($id);
        $v['recetas'] = $this->recetas($id);
        return $v;
    }

    public function examenes(int $idVisita): array {
        $stmt = $this->db->prepare(
            'SELECT ev.*, ec.nombre, u.nombre AS laboratorista FROM examenes_visita ev
             JOIN examenes_catalogo ec ON ec.id_examen = ev.id_examen
             LEFT JOIN usuarios u ON u.id_usuario = ev.id_laboratorista
             WHERE ev.id_visita = ? ORDER BY ev.id_examen_visita'
        );
        $stmt->execute([$idVisita]);
        return $stmt->fetchAll();
    }

    public function recetas(int $idVisita): array {
        $stmt = $this->db->prepare(
            'SELECT rv.*, m.nombre_comercial, m.presentacion, u.nombre AS farmaceutico FROM recetas_visita rv
             JOIN medicamentos m ON m.id_medicamento = rv.id_medicamento
             LEFT JOIN usuarios u ON u.id_usuario = rv.id_farmaceutico
             WHERE rv.id_visita = ? ORDER BY rv.id_receta'
        );
        $stmt->execute([$idVisita]);
        return $stmt->fetchAll();
    }

    /** Historial de visitas de un residente (ficha médica) */
    public function historial(int $idResidente): array {
        $stmt = $this->db->prepare(
            'SELECT v.id_visita FROM visitas_medicas v WHERE v.id_residente = ? ORDER BY v.fecha_visita DESC'
        );
        $stmt->execute([$idResidente]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $out[] = $this->completa((int)$id);
        return $out;
    }

    public function crear(array $d): int {
        $this->db->prepare(
            'INSERT INTO visitas_medicas (id_solicitud, id_residente, id_medico, motivo, costo_consulta, descuento_pct)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$d['id_solicitud'], $d['id_residente'], $d['id_medico'], $d['motivo'], $d['costo_consulta'], $d['descuento_pct']]);
        return (int)$this->db->lastInsertId();
    }

    public function guardarNotas(int $id, ?string $diagnostico, ?string $observaciones): void {
        $this->db->prepare('UPDATE visitas_medicas SET diagnostico = ?, observaciones = ? WHERE id_visita = ?')
            ->execute([$diagnostico, $observaciones, $id]);
    }

    public function finalizar(int $id): void {
        $this->db->prepare("UPDATE visitas_medicas SET estado = 'Finalizada' WHERE id_visita = ?")->execute([$id]);
    }

    public function agregarExamen(int $idVisita, array $d): int {
        $this->db->prepare(
            'INSERT INTO examenes_visita (id_visita, id_examen, indicaciones, precio, descuento_pct) VALUES (?, ?, ?, ?, ?)'
        )->execute([$idVisita, $d['id_examen'], $d['indicaciones'], $d['precio'], $d['descuento_pct']]);
        return (int)$this->db->lastInsertId();
    }

    public function obtenerExamen(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM examenes_visita WHERE id_examen_visita = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function quitarExamen(int $id): void {
        $this->db->prepare("DELETE FROM examenes_visita WHERE id_examen_visita = ? AND estado = 'Solicitado'")->execute([$id]);
    }

    public function agregarReceta(int $idVisita, array $d): int {
        $this->db->prepare(
            'INSERT INTO recetas_visita (id_visita, id_medicamento, cantidad, indicaciones, duracion_dias, precio_unitario, descuento_pct)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$idVisita, $d['id_medicamento'], $d['cantidad'], $d['indicaciones'], $d['duracion_dias'], $d['precio_unitario'], $d['descuento_pct']]);
        return (int)$this->db->lastInsertId();
    }

    public function obtenerReceta(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM recetas_visita WHERE id_receta = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function quitarReceta(int $id): void {
        $this->db->prepare("DELETE FROM recetas_visita WHERE id_receta = ? AND estado = 'Pendiente'")->execute([$id]);
    }
}
