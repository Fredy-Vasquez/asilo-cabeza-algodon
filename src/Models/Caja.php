<?php
// src/Models/Caja.php
// Módulo de entradas, salidas y manejo de caja:
// cuentas de los familiares (cargos y pagos), cuotas mensuales, deuda con la fundación por cita,
// donaciones y gastos del asilo.

require_once __DIR__ . '/../../config/database.php';

class Caja {
    public const METODOS_PAGO = ['Efectivo', 'Depósito', 'Transferencia', 'Tarjeta', 'Cheque'];
    public const METODOS_FUNDACION = ['Efectivo', 'Depósito', 'Transferencia', 'Cheque'];
    public const TIPOS_DONANTE = ['Empresa internacional', 'Empresa nacional', 'Gobierno', 'Particular'];
    public const TIPOS_DONACION = ['Monetaria', 'En especie'];
    public const CATEGORIAS_GASTO = ['Energía eléctrica', 'Agua', 'Teléfono e internet', 'Alimentación', 'Personal',
                                     'Mantenimiento', 'Insumos y limpieza', 'Otro'];
    /** Cargos que corresponden a servicios de la fundación (lo que el asilo le debe) */
    private const TIPOS_FUNDACION = "('Consulta', 'Examen', 'Medicamento')";

    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    /* ================= Cuentas de los familiares ================= */

    /** Una fila por residente con lo cargado, lo pagado y el saldo pendiente */
    public function cuentas(): array {
        return $this->db->query(
            "SELECT r.id_residente, r.nombre, r.apellido, r.estado, h.numero_habitacion,
                    f.id_familiar, f.nombre AS familiar_nombre, f.apellido AS familiar_apellido, f.telefono AS familiar_telefono,
                    p.nombre_plan,
                    COALESCE(c.cargado, 0) AS cargado, COALESCE(pg.pagado, 0) AS pagado,
                    ROUND(COALESCE(c.cargado, 0) - COALESCE(pg.pagado, 0), 2) AS saldo,
                    pg.ultimo_pago
             FROM residentes r
             JOIN familiares f ON f.id_familiar = r.id_familiar
             LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
             LEFT JOIN planes_servicio p ON p.id_plan = r.id_plan
             LEFT JOIN (SELECT id_residente, SUM(monto) AS cargado FROM cargos_familiares GROUP BY id_residente) c ON c.id_residente = r.id_residente
             LEFT JOIN (SELECT id_residente, SUM(monto) AS pagado, MAX(fecha) AS ultimo_pago FROM pagos_familiares WHERE anulado = 0 GROUP BY id_residente) pg ON pg.id_residente = r.id_residente
             ORDER BY saldo DESC, r.nombre"
        )->fetchAll();
    }

    public function saldo(int $idResidente): float {
        $stmt = $this->db->prepare(
            'SELECT (SELECT COALESCE(SUM(monto), 0) FROM cargos_familiares WHERE id_residente = ?)
                  - (SELECT COALESCE(SUM(monto), 0) FROM pagos_familiares WHERE id_residente = ? AND anulado = 0)'
        );
        $stmt->execute([$idResidente, $idResidente]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    /** Movimientos (cargos y pagos) en orden con saldo acumulado */
    public function estadoCuenta(int $idResidente): array {
        $cargos = $this->db->prepare(
            "SELECT 'cargo' AS clase, id_cargo AS id, fecha, tipo, concepto, monto_bruto, descuento, monto, periodo, id_visita, NULL AS metodo, NULL AS referencia,
                    0 AS anulado, NULL AS motivo_anulacion
             FROM cargos_familiares WHERE id_residente = ?"
        );
        $cargos->execute([$idResidente]);
        $pagos = $this->db->prepare(
            "SELECT 'pago' AS clase, p.id_pago AS id, p.fecha, 'Pago' AS tipo,
                    CONCAT('Pago en ', LOWER(p.metodo), IF(p.observaciones IS NULL OR p.observaciones = '', '', CONCAT(': ', p.observaciones))) AS concepto,
                    p.monto AS monto_bruto, 0 AS descuento, p.monto, NULL AS periodo, NULL AS id_visita, p.metodo, p.referencia,
                    p.anulado, p.motivo_anulacion
             FROM pagos_familiares p WHERE p.id_residente = ?"
        );
        $pagos->execute([$idResidente]);
        $mov = array_merge($cargos->fetchAll(), $pagos->fetchAll());
        // Por fecha; el mismo día, primero los cargos y luego los pagos
        usort($mov, fn($a, $b) => [$a['fecha'], $a['clase'] === 'pago', $a['id']] <=> [$b['fecha'], $b['clase'] === 'pago', $b['id']]);
        $saldo = 0.0; $cargado = 0.0; $pagado = 0.0; $descuentos = 0.0;
        foreach ($mov as &$m) {
            if ($m['clase'] === 'cargo') { $saldo += $m['monto']; $cargado += $m['monto']; $descuentos += $m['descuento']; }
            elseif (!(int)$m['anulado']) { $saldo -= $m['monto']; $pagado += $m['monto']; }   // un pago anulado no cuenta
            $m['saldo'] = round($saldo, 2);
        }
        unset($m);
        return ['movimientos' => $mov, 'cargado' => round($cargado, 2), 'pagado' => round($pagado, 2),
                'saldo' => round($saldo, 2), 'descuentos' => round($descuentos, 2)];
    }

    public function registrarPago(array $d): int {
        $this->db->prepare(
            'INSERT INTO pagos_familiares (id_residente, monto, fecha, metodo, referencia, observaciones, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['id_residente'], $d['monto'], $d['fecha'], $d['metodo'], $d['referencia'], $d['observaciones'], $d['id_usuario']]);
        return (int)$this->db->lastInsertId();
    }

    /* ================= Cuotas mensuales ================= */

    /** Residentes a los que corresponde cuota en el periodo (AAAA-MM), con su monto */
    public function residentesParaCuota(string $periodo): array {
        $stmt = $this->db->prepare(
            "SELECT r.id_residente, r.nombre, r.apellido, r.fecha_ingreso, p.nombre_plan, COALESCE(p.costo_base, 0) AS costo_plan,
                    h.numero_habitacion, h.tipo AS tipo_habitacion, COALESCE(h.costo_adicional, 0) AS costo_habitacion,
                    (SELECT COUNT(*) FROM cargos_familiares c WHERE c.id_residente = r.id_residente AND c.tipo = 'Cuota mensual' AND c.periodo = ?) AS ya_tiene
             FROM residentes r
             LEFT JOIN planes_servicio p ON p.id_plan = r.id_plan
             LEFT JOIN habitaciones h ON h.id_habitacion = r.id_habitacion
             WHERE r.estado IN ('activo', 'hospitalizado') AND r.fecha_ingreso <= LAST_DAY(?)
             ORDER BY r.nombre"
        );
        $stmt->execute([$periodo, $periodo . '-01']);
        return $stmt->fetchAll();
    }

    public function cargarCuota(int $idResidente, string $periodo, string $concepto, float $monto, int $idUsuario): void {
        $this->db->prepare(
            "INSERT INTO cargos_familiares (id_residente, tipo, concepto, monto_bruto, descuento, monto, periodo, fecha, id_usuario)
             VALUES (?, 'Cuota mensual', ?, ?, 0, ?, ?, ?, ?)"
        )->execute([$idResidente, mb_substr($concepto, 0, 255), $monto, $monto, $periodo, $periodo . '-01', $idUsuario]);
    }

    /* ================= Deuda con la fundación por cita ================= */

    /** Por cada visita: lo que el asilo debe a la fundación (precio especial) y lo pagado */
    public function deudaFundacion(): array {
        $filas = $this->db->query(
            "SELECT v.id_visita, v.id_solicitud, v.fecha_visita, v.estado AS visita_estado,
                    r.id_residente, r.nombre AS residente_nombre, r.apellido AS residente_apellido,
                    e.nombre AS especialidad, u.nombre AS medico,
                    COALESCE(c.consulta, 0) AS consulta, COALESCE(c.examenes, 0) AS examenes, COALESCE(c.medicamentos, 0) AS medicamentos,
                    COALESCE(c.total, 0) AS adeudado, COALESCE(pf.pagado, 0) AS pagado, pf.ultimo_pago,
                    ROUND(COALESCE(c.total, 0) - COALESCE(pf.pagado, 0), 2) AS saldo
             FROM visitas_medicas v
             JOIN residentes r ON r.id_residente = v.id_residente
             JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
             JOIN especialidades e ON e.id_especialidad = s.id_especialidad
             JOIN usuarios u ON u.id_usuario = v.id_medico
             LEFT JOIN (SELECT id_visita,
                               SUM(CASE WHEN tipo = 'Consulta' THEN monto ELSE 0 END) AS consulta,
                               SUM(CASE WHEN tipo = 'Examen' THEN monto ELSE 0 END) AS examenes,
                               SUM(CASE WHEN tipo = 'Medicamento' THEN monto ELSE 0 END) AS medicamentos,
                               SUM(monto) AS total
                        FROM cargos_familiares WHERE id_visita IS NOT NULL AND tipo IN " . self::TIPOS_FUNDACION . "
                        GROUP BY id_visita) c ON c.id_visita = v.id_visita
             LEFT JOIN (SELECT id_visita, SUM(monto) AS pagado, MAX(fecha) AS ultimo_pago FROM pagos_fundacion WHERE anulado = 0 GROUP BY id_visita) pf ON pf.id_visita = v.id_visita
             ORDER BY saldo DESC, v.fecha_visita DESC"
        )->fetchAll();
        foreach ($filas as &$f) {
            $f['estado_pago'] = (float)$f['adeudado'] <= 0 ? 'Sin costo'
                : ((float)$f['saldo'] <= 0 ? 'Cancelada' : ((float)$f['pagado'] > 0 ? 'Parcial' : 'Pendiente'));
        }
        unset($f);
        return $filas;
    }

    public function deudaVisita(int $idVisita): ?array {
        foreach ($this->deudaFundacion() as $f) if ((int)$f['id_visita'] === $idVisita) return $f;
        return null;
    }

    public function pagosFundacion(?int $idVisita = null): array {
        $stmt = $this->db->prepare(
            'SELECT pf.*, u.nombre AS registrado_por, ua.nombre AS anulado_por_nombre FROM pagos_fundacion pf LEFT JOIN usuarios u ON u.id_usuario = pf.id_usuario LEFT JOIN usuarios ua ON ua.id_usuario = pf.anulado_por' .
            ($idVisita ? ' WHERE pf.id_visita = ?' : '') . ' ORDER BY pf.fecha DESC, pf.id_pago_fundacion DESC'
        );
        $stmt->execute($idVisita ? [$idVisita] : []);
        return $stmt->fetchAll();
    }

    public function registrarPagoFundacion(array $d): int {
        $this->db->prepare(
            'INSERT INTO pagos_fundacion (id_visita, monto, fecha, metodo, referencia, id_usuario) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$d['id_visita'], $d['monto'], $d['fecha'], $d['metodo'], $d['referencia'], $d['id_usuario']]);
        return (int)$this->db->lastInsertId();
    }

    /* ================= Donaciones y gastos ================= */

    public function donaciones(?string $desde = null, ?string $hasta = null): array {
        [$where, $p] = $this->rango('d.fecha', $desde, $hasta);
        $stmt = $this->db->prepare("SELECT d.*, u.nombre AS registrado_por, ua.nombre AS anulado_por_nombre FROM donaciones d LEFT JOIN usuarios u ON u.id_usuario = d.id_usuario LEFT JOIN usuarios ua ON ua.id_usuario = d.anulado_por $where ORDER BY d.fecha DESC, d.id_donacion DESC");
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    public function registrarDonacion(array $d): int {
        $this->db->prepare(
            'INSERT INTO donaciones (tipo_donante, donante, tipo_donacion, monto, descripcion, fecha, comprobante, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['tipo_donante'], $d['donante'], $d['tipo_donacion'], $d['monto'], $d['descripcion'], $d['fecha'], $d['comprobante'], $d['id_usuario']]);
        return (int)$this->db->lastInsertId();
    }

    public function gastos(?string $desde = null, ?string $hasta = null): array {
        [$where, $p] = $this->rango('g.fecha', $desde, $hasta);
        $stmt = $this->db->prepare("SELECT g.*, u.nombre AS registrado_por, ua.nombre AS anulado_por_nombre FROM gastos g LEFT JOIN usuarios u ON u.id_usuario = g.id_usuario LEFT JOIN usuarios ua ON ua.id_usuario = g.anulado_por $where ORDER BY g.fecha DESC, g.id_gasto DESC");
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    public function registrarGasto(array $d): int {
        $this->db->prepare(
            'INSERT INTO gastos (categoria, descripcion, monto, fecha, comprobante, id_usuario) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$d['categoria'], $d['descripcion'], $d['monto'], $d['fecha'], $d['comprobante'], $d['id_usuario']]);
        return (int)$this->db->lastInsertId();
    }

    /* ================= Anulación (un registro equivocado nunca se borra) ================= */

    /** tipo => [tabla, llave primaria] */
    public const ANULABLES = [
        'pago'           => ['pagos_familiares', 'id_pago'],
        'pago_fundacion' => ['pagos_fundacion', 'id_pago_fundacion'],
        'donacion'       => ['donaciones', 'id_donacion'],
        'gasto'          => ['gastos', 'id_gasto'],
    ];

    public function registroAnulable(string $tipo, int $id): ?array {
        [$tabla, $pk] = self::ANULABLES[$tipo];
        $stmt = $this->db->prepare("SELECT * FROM {$tabla} WHERE {$pk} = ? FOR UPDATE");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function anular(string $tipo, int $id, string $motivo, int $idUsuario): void {
        [$tabla, $pk] = self::ANULABLES[$tipo];
        $this->db->prepare("UPDATE {$tabla} SET anulado = 1, motivo_anulacion = ?, anulado_por = ?, anulado_en = NOW() WHERE {$pk} = ? AND anulado = 0")
            ->execute([$motivo, $idUsuario, $id]);
    }

    /* ================= Resumen de caja ================= */

    /** Entradas y salidas de dinero en un rango de fechas */
    public function resumen(string $desde, string $hasta): array {
        $suma = function (string $sql) use ($desde, $hasta): float {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            return round((float)$stmt->fetchColumn(), 2);
        };
        $agrupar = function (string $sql) use ($desde, $hasta): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            return $stmt->fetchAll();
        };
        $cobros = $suma('SELECT COALESCE(SUM(monto), 0) FROM pagos_familiares WHERE anulado = 0 AND fecha BETWEEN ? AND ?');
        $donMon = $suma("SELECT COALESCE(SUM(monto), 0) FROM donaciones WHERE anulado = 0 AND tipo_donacion = 'Monetaria' AND fecha BETWEEN ? AND ?");
        $donEsp = $suma("SELECT COALESCE(SUM(monto), 0) FROM donaciones WHERE anulado = 0 AND tipo_donacion = 'En especie' AND fecha BETWEEN ? AND ?");
        $gastos = $suma('SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE anulado = 0 AND fecha BETWEEN ? AND ?');
        $pagFund = $suma('SELECT COALESCE(SUM(monto), 0) FROM pagos_fundacion WHERE anulado = 0 AND fecha BETWEEN ? AND ?');
        $cargado = $suma('SELECT COALESCE(SUM(monto), 0) FROM cargos_familiares WHERE fecha BETWEEN ? AND ?');

        $porCobrar = round((float)$this->db->query(
            'SELECT (SELECT COALESCE(SUM(monto), 0) FROM cargos_familiares) - (SELECT COALESCE(SUM(monto), 0) FROM pagos_familiares WHERE anulado = 0)'
        )->fetchColumn(), 2);
        $deudaFund = round(array_sum(array_map(fn($f) => max(0, (float)$f['saldo']), $this->deudaFundacion())), 2);

        return [
            'desde' => $desde, 'hasta' => $hasta,
            'entradas' => ['cobros_familiares' => $cobros, 'donaciones' => $donMon, 'total' => round($cobros + $donMon, 2)],
            'salidas' => ['gastos' => $gastos, 'pagos_fundacion' => $pagFund, 'total' => round($gastos + $pagFund, 2)],
            'balance' => round($cobros + $donMon - $gastos - $pagFund, 2),
            'donaciones_especie' => $donEsp,
            'cargado_familiares' => $cargado,
            'por_cobrar' => $porCobrar,
            'deuda_fundacion' => $deudaFund,
            'donaciones_por_tipo' => $agrupar(
                'SELECT tipo_donante, tipo_donacion, COUNT(*) AS cantidad, SUM(monto) AS total FROM donaciones
                 WHERE anulado = 0 AND fecha BETWEEN ? AND ? GROUP BY tipo_donante, tipo_donacion ORDER BY total DESC'),
            'gastos_por_categoria' => $agrupar(
                'SELECT categoria, COUNT(*) AS cantidad, SUM(monto) AS total FROM gastos WHERE anulado = 0 AND fecha BETWEEN ? AND ? GROUP BY categoria ORDER BY total DESC'),
            'cargos_por_tipo' => $agrupar(
                'SELECT tipo, COUNT(*) AS cantidad, SUM(monto) AS total, SUM(descuento) AS descuento FROM cargos_familiares
                 WHERE fecha BETWEEN ? AND ? GROUP BY tipo ORDER BY total DESC'),
        ];
    }

    public function porCobrar(): float {
        return round((float)$this->db->query(
            'SELECT (SELECT COALESCE(SUM(monto), 0) FROM cargos_familiares) - (SELECT COALESCE(SUM(monto), 0) FROM pagos_familiares WHERE anulado = 0)'
        )->fetchColumn(), 2);
    }

    private function rango(string $col, ?string $desde, ?string $hasta): array {
        $w = []; $p = [];
        if ($desde) { $w[] = "$col >= ?"; $p[] = $desde; }
        if ($hasta) { $w[] = "$col <= ?"; $p[] = $hasta; }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }
}
