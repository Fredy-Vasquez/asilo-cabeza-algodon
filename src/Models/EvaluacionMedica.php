<?php
// src/Models/EvaluacionMedica.php

require_once __DIR__ . '/../../config/database.php';

class EvaluacionMedica {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    public function registrar(array $d): int {
        $stmt = $this->db->prepare(
            'INSERT INTO evaluaciones_medicas
               (id_residente, id_usuario, peso_kg, altura_m, imc, categoria_imc, presion_sistolica, presion_diastolica,
                glucosa, ritmo_cardiaco, diagnostico, nivel_alerta, origen_calculo)
             VALUES (:id_residente, :id_usuario, :peso_kg, :altura_m, :imc, :categoria_imc, :sis, :dia,
                     :glucosa, :pulso, :diagnostico, :nivel_alerta, :origen)'
        );
        $stmt->execute([
            ':id_residente' => $d['id_residente'], ':id_usuario' => $d['id_usuario'],
            ':peso_kg' => $d['peso_kg'], ':altura_m' => $d['altura_m'], ':imc' => $d['imc'],
            ':categoria_imc' => $d['categoria_imc'], ':sis' => $d['presion_sistolica'], ':dia' => $d['presion_diastolica'],
            ':glucosa' => $d['glucosa'], ':pulso' => $d['ritmo_cardiaco'], ':diagnostico' => $d['diagnostico'],
            ':nivel_alerta' => $d['nivel_alerta'], ':origen' => $d['origen_calculo'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function porResidente(int $idResidente): array {
        $stmt = $this->db->prepare(
            'SELECT e.*, u.nombre AS evaluador FROM evaluaciones_medicas e
             JOIN usuarios u ON u.id_usuario = e.id_usuario
             WHERE e.id_residente = ? ORDER BY e.fecha_evaluacion DESC, e.id_evaluacion DESC'
        );
        $stmt->execute([$idResidente]);
        return $stmt->fetchAll();
    }

    /** Última evaluación de cada residente activo con nivel distinto de Normal */
    public function alertasVigentes(): array {
        return $this->db->query(
            "SELECT e.id_evaluacion, e.id_residente, e.fecha_evaluacion, e.imc, e.categoria_imc, e.nivel_alerta,
                    e.presion_sistolica, e.presion_diastolica, e.glucosa, e.diagnostico,
                    r.nombre, r.apellido, h.numero_habitacion
             FROM evaluaciones_medicas e
             JOIN residentes r ON r.id_residente = e.id_residente
             LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
             WHERE r.estado <> 'inactivo'
               AND e.id_evaluacion = (SELECT e2.id_evaluacion FROM evaluaciones_medicas e2
                                      WHERE e2.id_residente = e.id_residente
                                      ORDER BY e2.fecha_evaluacion DESC, e2.id_evaluacion DESC LIMIT 1)
               AND e.nivel_alerta <> 'Normal'
             ORDER BY CASE e.nivel_alerta WHEN 'Urgente' THEN 0 ELSE 1 END, e.fecha_evaluacion DESC"
        )->fetchAll();
    }

    public function recientes(int $limite = 30): array {
        return $this->db->query(
            'SELECT e.id_evaluacion, e.id_residente, e.fecha_evaluacion, e.peso_kg, e.altura_m, e.imc, e.categoria_imc,
                    e.presion_sistolica, e.presion_diastolica, e.glucosa, e.ritmo_cardiaco, e.nivel_alerta, e.origen_calculo,
                    r.nombre, r.apellido, u.nombre AS evaluador
             FROM evaluaciones_medicas e
             JOIN residentes r ON r.id_residente = e.id_residente
             JOIN usuarios u ON u.id_usuario = e.id_usuario
             ORDER BY e.fecha_evaluacion DESC, e.id_evaluacion DESC LIMIT ' . max(1, min($limite, 200))
        )->fetchAll();
    }

    public function totalMes(): int {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM evaluaciones_medicas WHERE fecha_evaluacion >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn();
    }
}
