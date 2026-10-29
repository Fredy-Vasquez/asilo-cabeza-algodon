<?php
// src/Models/Reporte.php
// Consultas de los 7 reportes del enunciado (Módulo de Reportes).
// Todas aceptan un residente opcional y un rango de fechas opcional.

require_once __DIR__ . '/../../config/database.php';

class Reporte {
    private PDO $db;

    public function __construct() {
        $this->db = Database::conn();
    }

    /** Arma condiciones WHERE para residente y rango de fechas sobre una columna DATE/DATETIME */
    private function filtros(?int $idRes, ?string $desde, ?string $hasta, string $colRes, string $colFecha): array {
        $w = []; $p = [];
        if ($idRes) { $w[] = "$colRes = ?"; $p[] = $idRes; }
        if ($desde) { $w[] = "DATE($colFecha) >= ?"; $p[] = $desde; }
        if ($hasta) { $w[] = "DATE($colFecha) <= ?"; $p[] = $hasta; }
        return [$w, $p];
    }

    private function todos(string $sql, array $p = []): array {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /* ---------- 1. Costos de cada cita por paciente (consulta + exámenes + medicamentos) ---------- */
    public function costosCitas(?int $idRes, ?string $desde, ?string $hasta): array {
        [$w, $p] = $this->filtros($idRes, $desde, $hasta, 'v.id_residente', 'v.fecha_visita');
        $visitas = $this->todos(
            "SELECT v.id_visita, v.fecha_visita, v.estado, v.motivo, v.diagnostico, v.costo_consulta, v.descuento_pct,
                    r.id_residente, r.nombre, r.apellido, e.nombre AS especialidad, u.nombre AS medico
             FROM visitas_medicas v
             JOIN residentes r ON r.id_residente = v.id_residente
             JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
             JOIN especialidades e ON e.id_especialidad = s.id_especialidad
             JOIN usuarios u ON u.id_usuario = v.id_medico" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.apellido, r.nombre, v.fecha_visita',
            $p
        );
        $ex = $this->db->prepare(
            'SELECT ec.nombre, ev.precio, ev.descuento_pct, ev.estado FROM examenes_visita ev
             JOIN examenes_catalogo ec ON ec.id_examen = ev.id_examen WHERE ev.id_visita = ? ORDER BY ev.id_examen_visita'
        );
        $me = $this->db->prepare(
            'SELECT m.nombre_comercial AS nombre, rv.cantidad, rv.precio_unitario, rv.descuento_pct, rv.estado FROM recetas_visita rv
             JOIN medicamentos m ON m.id_medicamento = rv.id_medicamento WHERE rv.id_visita = ? ORDER BY rv.id_receta'
        );
        $neto = fn($bruto, $pct) => round($bruto * (1 - $pct / 100), 2);
        foreach ($visitas as &$v) {
            $finalizada = $v['estado'] === 'Finalizada';
            $v['consulta'] = ['bruto' => (float)$v['costo_consulta'], 'neto' => $finalizada ? $neto($v['costo_consulta'], $v['descuento_pct']) : 0.0, 'cobrada' => $finalizada];
            $ex->execute([$v['id_visita']]);
            $v['examenes'] = array_map(fn($x) => $x + ['neto' => $neto($x['precio'], $x['descuento_pct']), 'cobrado' => $x['estado'] === 'Realizado'], $ex->fetchAll());
            $me->execute([$v['id_visita']]);
            $v['medicamentos'] = array_map(fn($x) => $x + ['bruto' => round($x['precio_unitario'] * $x['cantidad'], 2),
                'neto' => $neto($x['precio_unitario'] * $x['cantidad'], $x['descuento_pct']), 'cobrado' => $x['estado'] === 'Entregado'], $me->fetchAll());
            $tEx = array_sum(array_map(fn($x) => $x['cobrado'] ? $x['neto'] : 0, $v['examenes']));
            $tMe = array_sum(array_map(fn($x) => $x['cobrado'] ? $x['neto'] : 0, $v['medicamentos']));
            $bruto = ($finalizada ? (float)$v['costo_consulta'] : 0)
                + array_sum(array_map(fn($x) => $x['cobrado'] ? (float)$x['precio'] : 0, $v['examenes']))
                + array_sum(array_map(fn($x) => $x['cobrado'] ? (float)$x['bruto'] : 0, $v['medicamentos']));
            $v['total_examenes'] = round($tEx, 2);
            $v['total_medicamentos'] = round($tMe, 2);
            $v['total'] = round($v['consulta']['neto'] + $tEx + $tMe, 2);
            $v['total_bruto'] = round($bruto, 2);
            $v['ahorro'] = round($bruto - $v['total'], 2);
            unset($v['costo_consulta']);
        }
        unset($v);
        return $visitas;
    }

    /* ---------- 2. Análisis médico por paciente (ficha, motivo de ingreso, medicamentos) ---------- */
    // Se arma en el controlador con los modelos existentes (Residente, FichaMedica, EvaluacionMedica, VisitaMedica).

    /* ---------- 3. Cobros por paciente por rango de fechas (con gastos médicos como detalle) ---------- */
    public function cargos(?int $idRes, ?string $desde, ?string $hasta): array {
        [$w, $p] = $this->filtros($idRes, $desde, $hasta, 'c.id_residente', 'c.fecha');
        return $this->todos(
            "SELECT c.id_cargo, c.id_residente, r.nombre, r.apellido, c.fecha, c.tipo, c.concepto, c.monto_bruto, c.descuento, c.monto, c.periodo
             FROM cargos_familiares c JOIN residentes r ON r.id_residente = c.id_residente" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.apellido, r.nombre, c.fecha, c.id_cargo',
            $p
        );
    }

    public function pagosFamiliares(?int $idRes, ?string $desde, ?string $hasta): array {
        [$w, $p] = $this->filtros($idRes, $desde, $hasta, 'pf.id_residente', 'pf.fecha');
        $w[] = 'pf.anulado = 0';
        return $this->todos(
            "SELECT pf.id_pago, pf.id_residente, r.nombre, r.apellido, CONCAT(f.nombre, ' ', f.apellido) AS familiar,
                    pf.fecha, pf.monto, pf.metodo, pf.referencia, pf.observaciones
             FROM pagos_familiares pf JOIN residentes r ON r.id_residente = pf.id_residente
             JOIN familiares f ON f.id_familiar = r.id_familiar" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY pf.fecha, pf.id_pago',
            $p
        );
    }

    /** Saldo de cada residente hasta una fecha (o al día de hoy) */
    public function saldos(?int $idRes, ?string $hasta): array {
        $h = $hasta ?: '9999-12-31';
        $p = [$h, $h];
        $sql = "SELECT r.id_residente, r.nombre, r.apellido,
                       (SELECT COALESCE(SUM(monto), 0) FROM cargos_familiares c WHERE c.id_residente = r.id_residente AND c.fecha <= ?)
                     - (SELECT COALESCE(SUM(monto), 0) FROM pagos_familiares pf WHERE pf.id_residente = r.id_residente AND pf.anulado = 0 AND pf.fecha <= ?) AS saldo
                FROM residentes r";
        if ($idRes) { $sql .= ' WHERE r.id_residente = ?'; $p[] = $idRes; }
        $out = [];
        foreach ($this->todos($sql, $p) as $f) $out[(int)$f['id_residente']] = round((float)$f['saldo'], 2);
        return $out;
    }

    /* ---------- 4. Pagos a la fundación ---------- */
    public function pagosFundacion(?int $idRes, ?string $desde, ?string $hasta): array {
        [$w, $p] = $this->filtros($idRes, $desde, $hasta, 'v.id_residente', 'pf.fecha');
        $w[] = 'pf.anulado = 0';
        return $this->todos(
            "SELECT pf.id_pago_fundacion, pf.fecha, pf.monto, pf.metodo, pf.referencia, pf.id_visita,
                    v.fecha_visita, r.nombre, r.apellido, e.nombre AS especialidad, u.nombre AS medico
             FROM pagos_fundacion pf
             JOIN visitas_medicas v ON v.id_visita = pf.id_visita
             JOIN residentes r ON r.id_residente = v.id_residente
             JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
             JOIN especialidades e ON e.id_especialidad = s.id_especialidad
             JOIN usuarios u ON u.id_usuario = v.id_medico" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY pf.fecha, pf.id_pago_fundacion',
            $p
        );
    }

    /* ---------- 5. Entradas: donaciones y cobros ---------- */
    public function donaciones(?string $desde, ?string $hasta): array {
        [$w, $p] = $this->filtros(null, $desde, $hasta, '', 'd.fecha');
        $w[] = 'd.anulado = 0';
        return $this->todos(
            'SELECT d.id_donacion, d.fecha, d.tipo_donante, d.donante, d.tipo_donacion, d.monto, d.descripcion, d.comprobante FROM donaciones d' .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY d.fecha, d.id_donacion',
            $p
        );
    }

    /* ---------- 6. Exámenes realizados por paciente ---------- */
    public function examenes(?int $idRes, ?string $desde, ?string $hasta, bool $soloRealizados = true): array {
        [$w, $p] = $this->filtros($idRes, $desde, $hasta, 'v.id_residente', 'COALESCE(ev.fecha_resultado, v.fecha_visita)');
        if ($soloRealizados) $w[] = "ev.estado = 'Realizado'";
        return $this->todos(
            "SELECT ev.id_examen_visita, ec.nombre AS examen, ev.estado, ev.resultado, ev.fecha_resultado, ev.indicaciones,
                    ev.precio, ev.descuento_pct, ROUND(ev.precio * (1 - ev.descuento_pct / 100), 2) AS neto,
                    v.fecha_visita, r.id_residente, r.nombre, r.apellido, e.nombre AS especialidad,
                    u.nombre AS medico, lab.nombre AS laboratorista
             FROM examenes_visita ev
             JOIN examenes_catalogo ec ON ec.id_examen = ev.id_examen
             JOIN visitas_medicas v ON v.id_visita = ev.id_visita
             JOIN residentes r ON r.id_residente = v.id_residente
             JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
             JOIN especialidades e ON e.id_especialidad = s.id_especialidad
             JOIN usuarios u ON u.id_usuario = v.id_medico
             LEFT JOIN usuarios lab ON lab.id_usuario = ev.id_laboratorista" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.apellido, r.nombre, COALESCE(ev.fecha_resultado, v.fecha_visita)',
            $p
        );
    }

    /* ---------- 7. Medicamentos aplicados por paciente ---------- */
    public function medicamentosEntregados(?int $idRes, ?string $desde, ?string $hasta): array {
        [$w, $p] = $this->filtros($idRes, $desde, $hasta, 'v.id_residente', 'rv.fecha_entrega');
        $w[] = "rv.estado = 'Entregado'";
        return $this->todos(
            "SELECT rv.id_receta, m.nombre_comercial, m.componente_activo, m.presentacion, rv.cantidad, rv.indicaciones, rv.duracion_dias,
                    rv.fecha_entrega, rv.precio_unitario, rv.descuento_pct,
                    ROUND(rv.precio_unitario * rv.cantidad * (1 - rv.descuento_pct / 100), 2) AS neto,
                    v.fecha_visita, r.id_residente, r.nombre, r.apellido, e.nombre AS especialidad, u.nombre AS medico
             FROM recetas_visita rv
             JOIN medicamentos m ON m.id_medicamento = rv.id_medicamento
             JOIN visitas_medicas v ON v.id_visita = rv.id_visita
             JOIN residentes r ON r.id_residente = v.id_residente
             JOIN solicitudes_medicas s ON s.id_solicitud = v.id_solicitud
             JOIN especialidades e ON e.id_especialidad = s.id_especialidad
             JOIN usuarios u ON u.id_usuario = v.id_medico
             WHERE " . implode(' AND ', $w) . ' ORDER BY r.apellido, r.nombre, rv.fecha_entrega',
            $p
        );
    }

    /** Medicinas de cajón (permanentes) activas */
    public function permanentes(?int $idRes): array {
        return $this->todos(
            "SELECT mp.id_residente, r.nombre, r.apellido, m.nombre_comercial, m.presentacion, mp.dosis, mp.frecuencia, mp.indicaciones, mp.fecha_inicio
             FROM medicamentos_permanentes mp
             JOIN medicamentos m ON m.id_medicamento = mp.id_medicamento
             JOIN residentes r ON r.id_residente = mp.id_residente
             WHERE mp.activo = 1" . ($idRes ? ' AND mp.id_residente = ?' : '') . ' ORDER BY r.apellido, r.nombre, m.nombre_comercial',
            $idRes ? [$idRes] : []
        );
    }
}
