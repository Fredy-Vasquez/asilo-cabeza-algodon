<?php
// src/Models/SolicitudMedica.php
// Solicitud de atención especializada: la crea el médico general y la programa la fundación.

require_once __DIR__ . '/../../config/database.php';

class SolicitudMedica {
    public const ESTADOS = ['Pendiente', 'Programada', 'Atendida', 'Cancelada'];
    public const PRIORIDADES = ['Normal', 'Urgente'];

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    private const SELECT = "SELECT s.*, r.nombre AS residente_nombre, r.apellido AS residente_apellido, r.estado AS residente_estado,
            TIMESTAMPDIFF(YEAR, r.fecha_nacimiento, CURDATE()) AS residente_edad, h.numero_habitacion,
            e.nombre AS especialidad, e.costo_consulta,
            mg.nombre AS medico_general, enf.nombre AS enfermero, enf.telefono AS enfermero_telefono,
            ma.nombre AS medico_asignado,
            v.id_visita, v.estado AS visita_estado
        FROM solicitudes_medicas s
        JOIN residentes r ON r.id_residente = s.id_residente
        LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
        JOIN especialidades e ON e.id_especialidad = s.id_especialidad
        JOIN usuarios mg ON mg.id_usuario = s.id_medico_general
        LEFT JOIN usuarios enf ON enf.id_usuario = s.id_enfermero
        LEFT JOIN usuarios ma ON ma.id_usuario = s.id_medico_asignado
        LEFT JOIN visitas_medicas v ON v.id_solicitud = s.id_solicitud";

    /**
     * @param array $filtro estado, id_enfermero, id_medico_asignado, id_residente
     */
    public function listar(array $filtro = []): array {
        $where = []; $p = [];
        foreach (['estado' => 's.estado', 'id_enfermero' => 's.id_enfermero', 'id_medico_asignado' => 's.id_medico_asignado', 'id_residente' => 's.id_residente'] as $k => $col) {
            if (isset($filtro[$k]) && $filtro[$k] !== '' && $filtro[$k] !== null) { $where[] = "$col = ?"; $p[] = $filtro[$k]; }
        }
        $sql = self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
            " ORDER BY CASE s.estado WHEN 'Pendiente' THEN 0 WHEN 'Programada' THEN 1 ELSE 2 END,
                       CASE WHEN s.estado = 'Pendiente' AND s.prioridad = 'Urgente' THEN 0 ELSE 1 END,
                       CASE WHEN s.estado = 'Programada' THEN s.fecha_programada END ASC,
                       s.fecha_solicitud DESC
              LIMIT 300";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    public function obtener(int $id, bool $bloquear = false): ?array {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE s.id_solicitud = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function crear(array $d): int {
        $this->db->prepare(
            'INSERT INTO solicitudes_medicas (id_residente, id_medico_general, id_especialidad, id_enfermero, motivo, prioridad)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$d['id_residente'], $d['id_medico_general'], $d['id_especialidad'], $d['id_enfermero'], $d['motivo'], $d['prioridad']]);
        return (int)$this->db->lastInsertId();
    }

    public function programar(int $id, int $idMedico, string $fechaHora, ?int $idEnfermero, ?string $notas): void {
        $this->db->prepare(
            "UPDATE solicitudes_medicas SET id_medico_asignado = ?, fecha_programada = ?, id_enfermero = ?, notas_fundacion = ?, estado = 'Programada'
             WHERE id_solicitud = ?"
        )->execute([$idMedico, $fechaHora, $idEnfermero, $notas, $id]);
    }

    public function cambiarEstado(int $id, string $estado, ?string $notas = null): void {
        $sql = 'UPDATE solicitudes_medicas SET estado = ?' . ($notas !== null ? ', notas_fundacion = ?' : '') . ' WHERE id_solicitud = ?';
        $p = $notas !== null ? [$estado, $notas, $id] : [$estado, $id];
        $this->db->prepare($sql)->execute($p);
    }

    /** Citas del médico que se cruzan con el horario propuesto (bloques de 45 minutos) */
    public function choquesHorario(int $idMedico, string $fechaHora, int $excluir): array {
        $stmt = $this->db->prepare(
            "SELECT s.id_solicitud, s.fecha_programada, r.nombre, r.apellido FROM solicitudes_medicas s
             JOIN residentes r ON r.id_residente = s.id_residente
             WHERE s.id_medico_asignado = ? AND s.estado = 'Programada' AND s.id_solicitud <> ?
               AND s.fecha_programada > DATE_SUB(?, INTERVAL 45 MINUTE)
               AND s.fecha_programada < DATE_ADD(?, INTERVAL 45 MINUTE)"
        );
        $stmt->execute([$idMedico, $excluir, $fechaHora, $fechaHora]);
        return $stmt->fetchAll();
    }

    public function contarPorEstado(): array {
        $out = array_fill_keys(self::ESTADOS, 0);
        foreach ($this->db->query('SELECT estado, COUNT(*) AS n FROM solicitudes_medicas GROUP BY estado') as $f) {
            $out[$f['estado']] = (int)$f['n'];
        }
        return $out;
    }

    public function proximas(int $limite = 6, array $filtro = []): array {
        $where = ["s.estado = 'Programada'", 's.fecha_programada >= CURDATE()'];
        $p = [];
        foreach (['id_enfermero' => 's.id_enfermero', 'id_medico_asignado' => 's.id_medico_asignado'] as $k => $col) {
            if (!empty($filtro[$k])) { $where[] = "$col = ?"; $p[] = $filtro[$k]; }
        }
        $stmt = $this->db->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.fecha_programada LIMIT ' . (int)$limite);
        $stmt->execute($p);
        return $stmt->fetchAll();
    }
}
