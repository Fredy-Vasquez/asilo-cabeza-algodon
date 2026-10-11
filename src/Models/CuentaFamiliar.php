<?php
// src/Models/CuentaFamiliar.php
// Cuenta corriente del familiar responsable: cargos (con el descuento de la fundación) y pagos.

require_once __DIR__ . '/../../config/database.php';

class CuentaFamiliar {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    /**
     * Registra un cargo aplicando el porcentaje de descuento indicado.
     * @return array{id:int, bruto:float, descuento:float, neto:float}
     */
    public function cargar(int $idResidente, string $tipo, string $concepto, float $montoBruto, float $descuentoPct,
                           ?int $idVisita = null, ?string $periodo = null, ?int $idUsuario = null): array {
        $descuento = round($montoBruto * $descuentoPct / 100, 2);
        $neto = round($montoBruto - $descuento, 2);
        $this->db->prepare(
            'INSERT INTO cargos_familiares (id_residente, tipo, concepto, id_visita, monto_bruto, descuento, monto, periodo, fecha, id_usuario)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)'
        )->execute([$idResidente, $tipo, mb_substr($concepto, 0, 255), $idVisita, $montoBruto, $descuento, $neto, $periodo, $idUsuario]);
        return ['id' => (int)$this->db->lastInsertId(), 'bruto' => $montoBruto, 'descuento' => $descuento, 'neto' => $neto];
    }
}
