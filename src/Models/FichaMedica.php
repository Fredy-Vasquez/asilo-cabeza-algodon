<?php
// src/Models/FichaMedica.php
// Ficha médica del interno: padecimientos (psicopatologías, enfermedades, alergias)
// y medicamentos permanentes ("medicinas de cajón").

require_once __DIR__ . '/../../config/database.php';

class FichaMedica {
    public const TIPOS = ['Psicopatología', 'Enfermedad crónica', 'Alergia', 'Discapacidad', 'Otro'];
    public const ESTADOS = ['Activo', 'Controlado', 'Resuelto'];

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    /* ---------- Padecimientos ---------- */

    public function padecimientos(int $idResidente): array {
        $stmt = $this->db->prepare(
            "SELECT p.*, u.nombre AS registrado_por FROM padecimientos p
             LEFT JOIN usuarios u ON u.id_usuario = p.id_usuario
             WHERE p.id_residente = ?
             ORDER BY CASE p.estado WHEN 'Activo' THEN 0 WHEN 'Controlado' THEN 1 ELSE 2 END, p.tipo, p.descripcion"
        );
        $stmt->execute([$idResidente]);
        return $stmt->fetchAll();
    }

    public function obtenerPadecimiento(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM padecimientos WHERE id_padecimiento = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function guardarPadecimiento(?int $id, array $d): int {
        if ($id) {
            $this->db->prepare(
                'UPDATE padecimientos SET tipo = ?, descripcion = ?, fecha_diagnostico = ?, estado = ?, notas = ?
                 WHERE id_padecimiento = ?'
            )->execute([$d['tipo'], $d['descripcion'], $d['fecha_diagnostico'], $d['estado'], $d['notas'], $id]);
            return $id;
        }
        $this->db->prepare(
            'INSERT INTO padecimientos (id_residente, tipo, descripcion, fecha_diagnostico, estado, notas, id_usuario)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['id_residente'], $d['tipo'], $d['descripcion'], $d['fecha_diagnostico'], $d['estado'], $d['notas'], $d['id_usuario']]);
        return (int)$this->db->lastInsertId();
    }

    public function eliminarPadecimiento(int $id): void {
        $this->db->prepare('DELETE FROM padecimientos WHERE id_padecimiento = ?')->execute([$id]);
    }

    /* ---------- Medicamentos permanentes ---------- */

    public function permanentes(int $idResidente): array {
        $stmt = $this->db->prepare(
            'SELECT mp.*, m.nombre_comercial, m.presentacion, m.componente_activo
             FROM medicamentos_permanentes mp
             JOIN medicamentos m ON m.id_medicamento = mp.id_medicamento
             WHERE mp.id_residente = ?
             ORDER BY mp.activo DESC, m.nombre_comercial'
        );
        $stmt->execute([$idResidente]);
        return $stmt->fetchAll();
    }

    public function obtenerPermanente(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM medicamentos_permanentes WHERE id_permanente = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function guardarPermanente(?int $id, array $d): int {
        if ($id) {
            $this->db->prepare(
                'UPDATE medicamentos_permanentes SET id_medicamento = ?, dosis = ?, frecuencia = ?, indicaciones = ?,
                        fecha_inicio = ?, activo = ? WHERE id_permanente = ?'
            )->execute([$d['id_medicamento'], $d['dosis'], $d['frecuencia'], $d['indicaciones'], $d['fecha_inicio'], $d['activo'], $id]);
            return $id;
        }
        $this->db->prepare(
            'INSERT INTO medicamentos_permanentes (id_residente, id_medicamento, dosis, frecuencia, indicaciones, fecha_inicio, activo, id_usuario)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['id_residente'], $d['id_medicamento'], $d['dosis'], $d['frecuencia'], $d['indicaciones'], $d['fecha_inicio'], $d['activo'], $d['id_usuario']]);
        return (int)$this->db->lastInsertId();
    }
}
