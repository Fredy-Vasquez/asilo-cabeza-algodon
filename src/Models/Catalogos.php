<?php
// src/Models/Catalogos.php
// Catálogos compartidos: medicamentos, especialidades, exámenes y configuración.

require_once __DIR__ . '/../../config/database.php';

class Catalogos {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function medicamentos(bool $soloActivos = true): array {
        return $this->db->query(
            'SELECT * FROM medicamentos' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre_comercial'
        )->fetchAll();
    }

    public function medicamento(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM medicamentos WHERE id_medicamento = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function especialidades(bool $soloActivas = true): array {
        return $this->db->query(
            'SELECT * FROM especialidades' . ($soloActivas ? ' WHERE activa = 1' : '') . ' ORDER BY nombre'
        )->fetchAll();
    }

    public function especialidad(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM especialidades WHERE id_especialidad = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function examenes(bool $soloActivos = true): array {
        return $this->db->query(
            'SELECT * FROM examenes_catalogo' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre'
        )->fetchAll();
    }

    public function config(string $clave, ?string $porDefecto = null): ?string {
        $stmt = $this->db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $stmt->execute([$clave]);
        $v = $stmt->fetchColumn();
        return $v === false ? $porDefecto : $v;
    }

    public function descuentoFundacion(): float {
        return (float)$this->config('descuento_fundacion_pct', '0');
    }
}
