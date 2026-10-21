<?php
// src/Controllers/ReporteController.php
// Módulo de reportes: los 7 informes del enunciado. Cada uno se filtra por residente y rango de fechas,
// se ve en pantalla, se imprime o guarda en PDF desde el navegador y se exporta a CSV.

require_once __DIR__ . '/../Models/Reporte.php';
require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Models/FichaMedica.php';
require_once __DIR__ . '/../Models/EvaluacionMedica.php';
require_once __DIR__ . '/../Models/VisitaMedica.php';
require_once __DIR__ . '/../Models/Caja.php';
require_once __DIR__ . '/../Models/Catalogos.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class ReporteController {
    /** tipo => [título, descripción, roles, ¿residente obligatorio?] */
    private const REPORTES = [
        'costos_citas'     => ['Costos de citas por paciente', 'Costo de cada cita con su consulta, exámenes de laboratorio y medicamentos, con el descuento de la fundación. Incluye estadísticas por paciente y especialidad.', [ROL_ADMIN, ROL_CONTADOR, ROL_MEDICO], false],
        'analisis_medico'  => ['Análisis médico por paciente', 'Ficha médica completa: motivo de ingreso, padecimientos, medicamentos, evaluaciones y visitas con diagnóstico.', [ROL_ADMIN, ROL_MEDICO], true],
        'cobros'           => ['Cobros por paciente', 'Cargos a la cuenta del familiar en el rango de fechas, con los gastos médicos como detalle, pagos recibidos y saldo.', [ROL_ADMIN, ROL_CONTADOR], false],
        'pagos_fundacion'  => ['Pagos a la fundación', 'Pagos realizados a la fundación por cita y citas que aún no están completamente canceladas.', [ROL_ADMIN, ROL_CONTADOR, ROL_FUNDACION], false],
        'entradas'         => ['Entradas: donaciones y cobros', 'Todo el dinero que ingresó al asilo: donaciones por tipo de donante y pagos de los familiares.', [ROL_ADMIN, ROL_CONTADOR], false],
        'examenes'         => ['Exámenes realizados por paciente', 'Exámenes de laboratorio con su resultado, quién los solicitó, quién los realizó y su costo.', [ROL_ADMIN, ROL_MEDICO], false],
        'medicamentos'     => ['Medicamentos aplicados por paciente', 'Medicamentos entregados por farmacia en cada visita y medicamentos permanentes (de cajón) vigentes.', [ROL_ADMIN, ROL_MEDICO], false],
    ];

    private Reporte $model;

    public function __construct() {
        $this->model = new Reporte();
    }

    /** Reportes disponibles para el rol y lista de residentes para el filtro */
    public function opciones(): array {
        $lista = [];
        foreach (self::REPORTES as $tipo => [$t, $d, $roles, $req]) {
            if (Auth::tieneRol($roles)) $lista[] = ['tipo' => $tipo, 'titulo' => $t, 'descripcion' => $d, 'requiere_residente' => $req];
        }
        $residentes = array_map(fn($r) => ['id' => (int)$r['id_residente'], 'nombre' => "{$r['nombre']} {$r['apellido']}", 'estado' => $r['estado']],
            (new Residente())->listar());
        return ['reportes' => $lista, 'residentes' => $residentes];
    }

    public function generar(array $in): array {
        $tipo = (string)($in['tipo'] ?? '');
        if (!isset(self::REPORTES[$tipo])) throw new HttpError('Seleccione un reporte válido.', 422);
        [$titulo, $desc, $roles, $requiere] = self::REPORTES[$tipo];
        if (!Auth::tieneRol($roles)) throw new HttpError('Su rol no tiene acceso a este reporte.', 403);

        $idRes = Http::entero($in, 'id_residente', $requiere, 1, null, 'Residente');
        $desde = Http::fecha($in, 'desde', false, 'Desde');
        $hasta = Http::fecha($in, 'hasta', false, 'Hasta');
        if ($desde && $hasta && $desde > $hasta) throw new HttpError('La fecha inicial es posterior a la final.', 422);
        $res = $idRes ? (new Residente())->obtener($idRes) : null;
        if ($idRes && !$res) throw new HttpError('El residente no existe.', 404);

        $datos = match ($tipo) {
            'costos_citas'    => $this->costosCitas($idRes, $desde, $hasta),
            'analisis_medico' => $this->analisisMedico($idRes, $desde, $hasta),
            'cobros'          => $this->cobros($idRes, $desde, $hasta),
            'pagos_fundacion' => $this->pagosFundacion($idRes, $desde, $hasta),
            'entradas'        => $this->entradas($desde, $hasta),
            'examenes'        => $this->examenes($idRes, $desde, $hasta),
            'medicamentos'    => $this->medicamentos($idRes, $desde, $hasta),
        };
        Bitacora::registrar('REPORTE', 'reportes', ['tipo' => $tipo, 'residente' => $idRes, 'desde' => $desde, 'hasta' => $hasta]);

        $u = Auth::usuario();
        return [
            'tipo' => $tipo, 'titulo' => $titulo, 'descripcion' => $desc,
            'asilo' => (new Catalogos())->config('nombre_asilo', 'Asilo de Ancianos Cabeza de Algodón'),
            'filtros' => ['residente' => $res ? "{$res['nombre']} {$res['apellido']}" : null, 'desde' => $desde, 'hasta' => $hasta],
            'generado' => ['fecha' => date('Y-m-d H:i'), 'por' => $u['nombre'] ?? ''],
            'datos' => $datos,
        ];
    }

    /* ---------- Armado de cada reporte ---------- */

    private function costosCitas(?int $idRes, ?string $desde, ?string $hasta): array {
        $citas = $this->model->costosCitas($idRes, $desde, $hasta);
        $porPaciente = []; $porEsp = [];
        foreach ($citas as $c) {
            $k = $c['nombre'] . ' ' . $c['apellido'];
            $porPaciente[$k] ??= ['paciente' => $k, 'citas' => 0, 'consultas' => 0, 'examenes' => 0, 'medicamentos' => 0, 'total' => 0, 'ahorro' => 0];
            $porPaciente[$k]['citas']++;
            $porPaciente[$k]['consultas'] += $c['consulta']['neto'];
            $porPaciente[$k]['examenes'] += $c['total_examenes'];
            $porPaciente[$k]['medicamentos'] += $c['total_medicamentos'];
            $porPaciente[$k]['total'] += $c['total'];
            $porPaciente[$k]['ahorro'] += $c['ahorro'];
            $porEsp[$c['especialidad']] ??= ['especialidad' => $c['especialidad'], 'citas' => 0, 'total' => 0];
            $porEsp[$c['especialidad']]['citas']++;
            $porEsp[$c['especialidad']]['total'] += $c['total'];
        }
        $red = fn($a) => array_map(fn($x) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $x), array_values($a));
        $total = array_sum(array_column($citas, 'total'));
        return [
            'citas' => $citas,
            'por_paciente' => $red($porPaciente),
            'por_especialidad' => $red($porEsp),
            'resumen' => ['citas' => count($citas), 'total' => round($total, 2),
                'ahorro' => round(array_sum(array_column($citas, 'ahorro')), 2),
                'promedio' => count($citas) ? round($total / count($citas), 2) : 0],
        ];
    }

    private function analisisMedico(int $idRes, ?string $desde, ?string $hasta): array {
        $r = (new Residente())->obtener($idRes);
        $ficha = new FichaMedica();
        $enRango = function (?string $f) use ($desde, $hasta): bool {
            $d = substr((string)$f, 0, 10);
            return (!$desde || $d >= $desde) && (!$hasta || $d <= $hasta);
        };
        $visitas = array_values(array_filter((new VisitaMedica())->historial($idRes), fn($v) => $enRango($v['fecha_visita'])));
        $evals = array_values(array_filter((new EvaluacionMedica())->porResidente($idRes), fn($e) => $enRango($e['fecha_evaluacion'])));
        return [
            'residente' => $r,
            'padecimientos' => $ficha->padecimientos($idRes),
            'permanentes' => $ficha->permanentes($idRes),
            'evaluaciones' => $evals,
            'visitas' => $visitas,
        ];
    }

    private function cobros(?int $idRes, ?string $desde, ?string $hasta): array {
        $cargos = $this->model->cargos($idRes, $desde, $hasta);
        $pagos = $this->model->pagosFamiliares($idRes, $desde, $hasta);
        $saldos = $this->model->saldos($idRes, $hasta);
        $pac = [];
        foreach ($cargos as $c) {
            $k = (int)$c['id_residente'];
            $pac[$k] ??= ['paciente' => "{$c['nombre']} {$c['apellido']}", 'cargos' => [], 'pagos' => [], 'por_tipo' => []];
            $pac[$k]['cargos'][] = $c;
            $pac[$k]['por_tipo'][$c['tipo']] = round(($pac[$k]['por_tipo'][$c['tipo']] ?? 0) + (float)$c['monto'], 2);
        }
        foreach ($pagos as $p) {
            $k = (int)$p['id_residente'];
            $pac[$k] ??= ['paciente' => "{$p['nombre']} {$p['apellido']}", 'cargos' => [], 'pagos' => [], 'por_tipo' => []];
            $pac[$k]['pagos'][] = $p;
        }
        foreach ($pac as $k => &$x) {
            $x['total_cargos'] = round(array_sum(array_column($x['cargos'], 'monto')), 2);
            $x['gastos_medicos'] = round(array_sum(array_map(fn($c) => in_array($c['tipo'], ['Consulta', 'Examen', 'Medicamento'], true) ? (float)$c['monto'] : 0, $x['cargos'])), 2);
            $x['descuentos'] = round(array_sum(array_column($x['cargos'], 'descuento')), 2);
            $x['total_pagos'] = round(array_sum(array_column($x['pagos'], 'monto')), 2);
            $x['saldo'] = $saldos[$k] ?? 0;
        }
        unset($x);
        uasort($pac, fn($a, $b) => strcmp($a['paciente'], $b['paciente']));
        $lista = array_values($pac);
        return [
            'pacientes' => $lista,
            'resumen' => [
                'cargos' => round(array_sum(array_column($lista, 'total_cargos')), 2),
                'gastos_medicos' => round(array_sum(array_column($lista, 'gastos_medicos')), 2),
                'descuentos' => round(array_sum(array_column($lista, 'descuentos')), 2),
                'pagos' => round(array_sum(array_column($lista, 'total_pagos')), 2),
                'saldo' => round(array_sum(array_column($lista, 'saldo')), 2),
            ],
        ];
    }

    private function pagosFundacion(?int $idRes, ?string $desde, ?string $hasta): array {
        $pagos = $this->model->pagosFundacion($idRes, $desde, $hasta);
        $porMes = [];
        foreach ($pagos as $p) {
            $m = substr($p['fecha'], 0, 7);
            $porMes[$m] = round(($porMes[$m] ?? 0) + (float)$p['monto'], 2);
        }
        $pendientes = array_values(array_filter((new Caja())->deudaFundacion(),
            fn($c) => (float)$c['saldo'] > 0 && (!$idRes || (int)$c['id_residente'] === $idRes)));
        return [
            'pagos' => $pagos,
            'por_mes' => $porMes,
            'pendientes' => $pendientes,
            'resumen' => ['pagado' => round(array_sum(array_column($pagos, 'monto')), 2), 'pagos' => count($pagos),
                'pendiente' => round(array_sum(array_column($pendientes, 'saldo')), 2), 'citas_pendientes' => count($pendientes)],
        ];
    }

    private function entradas(?string $desde, ?string $hasta): array {
        $don = $this->model->donaciones($desde, $hasta);
        $cob = $this->model->pagosFamiliares(null, $desde, $hasta);
        $porTipo = [];
        foreach (Caja::TIPOS_DONANTE as $t) $porTipo[$t] = ['dinero' => 0, 'especie' => 0, 'cantidad' => 0];
        foreach ($don as $d) {
            $porTipo[$d['tipo_donante']][$d['tipo_donacion'] === 'Monetaria' ? 'dinero' : 'especie'] += (float)$d['monto'];
            $porTipo[$d['tipo_donante']]['cantidad']++;
        }
        $porMes = [];
        foreach ($don as $d) if ($d['tipo_donacion'] === 'Monetaria') {
            $m = substr($d['fecha'], 0, 7);
            $porMes[$m]['donaciones'] = round(($porMes[$m]['donaciones'] ?? 0) + (float)$d['monto'], 2);
        }
        foreach ($cob as $c) {
            $m = substr($c['fecha'], 0, 7);
            $porMes[$m]['cobros'] = round(($porMes[$m]['cobros'] ?? 0) + (float)$c['monto'], 2);
        }
        ksort($porMes);
        $dinero = array_sum(array_map(fn($d) => $d['tipo_donacion'] === 'Monetaria' ? (float)$d['monto'] : 0, $don));
        $cobros = array_sum(array_column($cob, 'monto'));
        return [
            'donaciones' => $don, 'cobros' => $cob, 'por_tipo' => $porTipo, 'por_mes' => $porMes,
            'resumen' => ['donaciones' => round($dinero, 2), 'cobros' => round($cobros, 2), 'total' => round($dinero + $cobros, 2),
                'especie' => round(array_sum(array_map(fn($d) => $d['tipo_donacion'] === 'En especie' ? (float)$d['monto'] : 0, $don)), 2)],
        ];
    }

    private function examenes(?int $idRes, ?string $desde, ?string $hasta): array {
        $lista = $this->model->examenes($idRes, $desde, $hasta);
        $porTipo = [];
        foreach ($lista as $e) $porTipo[$e['examen']] = ($porTipo[$e['examen']] ?? 0) + 1;
        arsort($porTipo);
        return ['examenes' => $lista, 'por_tipo' => $porTipo,
                'resumen' => ['cantidad' => count($lista), 'pacientes' => count(array_unique(array_column($lista, 'id_residente'))),
                              'costo' => round(array_sum(array_column($lista, 'neto')), 2)]];
    }

    private function medicamentos(?int $idRes, ?string $desde, ?string $hasta): array {
        $ent = $this->model->medicamentosEntregados($idRes, $desde, $hasta);
        $perm = $this->model->permanentes($idRes);
        return ['entregados' => $ent, 'permanentes' => $perm,
                'resumen' => ['entregas' => count($ent), 'unidades' => array_sum(array_column($ent, 'cantidad')),
                              'costo' => round(array_sum(array_column($ent, 'neto')), 2), 'permanentes' => count($perm)]];
    }
}
