<?php
// src/Models/PlanServicio.php

require_once __DIR__ . '/../../config/database.php';

class PlanServicio {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function listar(): array {
        return $this->db->query('SELECT * FROM planes_servicio ORDER BY costo_base')->fetchAll();
    }

    public function obtener(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM planes_servicio WHERE id_plan = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
