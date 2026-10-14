<?php
// src/Models/Laboratorio.php
// Exámenes solicitados por los especialistas y sus resultados; catálogo de exámenes.

require_once __DIR__ . '/../../config/database.php';

class Laboratorio {
    public const ESTADOS = ['Solicitado', 'Realizado'];

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    private const SELECT = "SELECT ev.*, ec.nombre AS examen,
            v.fecha_visita, v.id_solicitud, v.id_residente, v.estado AS visita_estado,
            r.nombre AS residente_nombre, r.apellido AS residente_apellido, h.numero_habitacion,
            med.nombre AS medico, esp.nombre AS especialidad, lab.nombre AS laboratorista,
            ROUND(ev.precio * (1 - ev.descuento_pct / 100), 2) AS precio_neto
        FROM examenes_visita ev
        JOIN examenes_catalogo ec ON ec.id_examen = ev.id_examen
        JOIN visitas_medicas v ON v.id_visita = ev.id_visita
        JOIN residentes r ON r.id_residente = v.id_residente
        LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
        JOIN usuarios med ON med.id_usuario = v.id_medico
        JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
        JOIN especialidades esp ON esp.id_especialidad = s.id_especialidad
        LEFT JOIN usuarios lab ON lab.id_usuario = ev.id_laboratorista";

    /** Pendientes primero (los más antiguos arriba), luego los realizados más recientes */
    public function listar(?string $estado = null): array {
        $sql = self::SELECT . ($estado ? ' WHERE ev.estado = ?' : '') .
            " ORDER BY CASE ev.estado WHEN 'Solicitado' THEN 0 ELSE 1 END,
                       CASE WHEN ev.estado = 'Solicitado' THEN v.fecha_visita END ASC,
                       ev.fecha_resultado DESC
              LIMIT 300";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($estado ? [$estado] : []);
        return $stmt->fetchAll();
    }

    public function obtener(int $id, bool $bloquear = false): ?array {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE ev.id_examen_visita = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function registrarResultado(int $id, string $resultado, int $idUsuario): void {
        $this->db->prepare(
            "UPDATE examenes_visita SET estado = 'Realizado', resultado = ?, fecha_resultado = NOW(), id_laboratorista = ?
             WHERE id_examen_visita = ? AND estado = 'Solicitado'"
        )->execute([$resultado, $idUsuario, $id]);
    }

    public function contarPendientes(): int {
        return (int)$this->db->query("SELECT COUNT(*) FROM examenes_visita WHERE estado = 'Solicitado'")->fetchColumn();
    }

    /* ---------- Catálogo de exámenes ---------- */

    public function catalogo(): array {
        return $this->db->query(
            'SELECT ec.*, (SELECT COUNT(*) FROM examenes_visita ev WHERE ev.id_examen = ec.id_examen) AS veces
             FROM examenes_catalogo ec ORDER BY ec.activo DESC, ec.nombre'
        )->fetchAll();
    }

    public function existeNombre(string $nombre, ?int $exceptoId = null): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM examenes_catalogo WHERE nombre = ? AND id_examen <> ?');
        $stmt->execute([$nombre, $exceptoId ?? 0]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function guardarExamen(?int $id, string $nombre, float $precio, bool $activo): int {
        if ($id) {
            $this->db->prepare('UPDATE examenes_catalogo SET nombre = ?, precio = ?, activo = ? WHERE id_examen = ?')
                ->execute([$nombre, $precio, $activo ? 1 : 0, $id]);
            return $id;
        }
        $this->db->prepare('INSERT INTO examenes_catalogo (nombre, precio, activo) VALUES (?, ?, ?)')
            ->execute([$nombre, $precio, $activo ? 1 : 0]);
        return (int)$this->db->lastInsertId();
    }
}
