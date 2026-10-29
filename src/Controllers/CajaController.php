<?php
// src/Controllers/CajaController.php
// CU: El contador registra los pagos de los familiares, genera las cuotas mensuales, paga a la
//     fundación por cita, registra donaciones y gastos, y consulta el resumen de caja.

require_once __DIR__ . '/../Models/Caja.php';
require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class CajaController {
    private Caja $model;

    public function __construct() {
        $this->model = new Caja();
    }

    /* ---------- Resumen ---------- */

    public function resumen(array $in): array {
        [$desde, $hasta] = $this->rango($in, true);
        return $this->model->resumen($desde, $hasta);
    }

    /* ---------- Cuentas de los familiares ---------- */

    public function cuentas(): array {
        $cuentas = $this->model->cuentas();
        return [
            'cuentas' => $cuentas,
            'por_cobrar' => round(array_sum(array_map(fn($c) => max(0, (float)$c['saldo']), $cuentas)), 2),
            'periodo_actual' => date('Y-m'),
        ];
    }

    public function estadoCuenta(array $in): array {
        $id = Http::entero($in, 'id_residente', true, 1, null, 'Residente');
        $r = (new Residente())->obtener($id);
        if (!$r) throw new HttpError('El residente no existe.', 404);
        return ['residente' => $r] + $this->model->estadoCuenta($id);
    }

    public function registrarPago(array $in): array {
        $id = Http::entero($in, 'id_residente', true, 1, null, 'Residente');
        $monto = round(Http::numero($in, 'monto', true, 0.01, 1000000, 'Monto (Q)'), 2);
        $fecha = Http::fecha($in, 'fecha', false, 'Fecha del pago') ?? date('Y-m-d');
        if ($fecha > date('Y-m-d')) throw new HttpError('La fecha del pago no puede ser futura.', 422);
        $metodo = Http::opcion($in, 'metodo', Caja::METODOS_PAGO, null, 'Forma de pago');
        $ref = Http::texto($in, 'referencia', false, 60, 'No. de boleta o referencia');
        if ($metodo !== 'Efectivo' && !$ref) throw new HttpError("Indique el número de boleta o referencia del pago con {$metodo}.", 422);
        $obs = Http::texto($in, 'observaciones', false, 255, 'Observaciones');

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $r = (new Residente())->obtener($id);
            if (!$r) throw new HttpError('El residente no existe.', 404);
            // Bloquea la cuenta para que dos pagos simultáneos no superen el saldo
            $db->prepare('SELECT id_residente FROM residentes WHERE id_residente = ? FOR UPDATE')->execute([$id]);
            $saldo = $this->model->saldo($id);
            if ($saldo <= 0) throw new HttpError('La cuenta de este residente no tiene saldo pendiente.', 409);
            if ($monto > $saldo + 0.001) {
                throw new HttpError('El pago (Q ' . number_format($monto, 2) . ') es mayor que el saldo pendiente (Q ' . number_format($saldo, 2) . ').', 422);
            }
            $idPago = $this->model->registrarPago(['id_residente' => $id, 'monto' => $monto, 'fecha' => $fecha, 'metodo' => $metodo,
                'referencia' => $ref, 'observaciones' => $obs, 'id_usuario' => Auth::id()]);
            $nuevo = round($saldo - $monto, 2);
            Bitacora::registrar('PAGO_FAMILIAR', 'pagos_familiares', ['id' => $idPago, 'residente' => "{$r['nombre']} {$r['apellido']}",
                'monto' => $monto, 'metodo' => $metodo, 'saldo_anterior' => $saldo, 'saldo_nuevo' => $nuevo]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id' => $idPago, 'saldo' => $nuevo,
                'mensaje' => $nuevo <= 0 ? 'Pago registrado. La cuenta quedó al día.' : 'Abono registrado. Saldo pendiente: Q ' . number_format($nuevo, 2) . '.'];
    }

    /* ---------- Cuotas mensuales ---------- */

    public function cuotasPreview(array $in): array {
        $periodo = $this->periodo($in);
        $filas = $this->model->residentesParaCuota($periodo);
        foreach ($filas as &$f) $f += self::cuota($f, $periodo);
        unset($f);
        return ['periodo' => $periodo, 'residentes' => $filas];
    }

    public function generarCuotas(array $in): array {
        $periodo = $this->periodo($in);
        if ($periodo > date('Y-m', strtotime('+1 month'))) throw new HttpError('Solo se pueden generar cuotas hasta el mes siguiente.', 422);
        $mes = $this->nombreMes($periodo);
        $db = Database::conn();
        $db->beginTransaction();
        try {
            $generadas = 0; $omitidas = 0; $total = 0.0;
            foreach ($this->model->residentesParaCuota($periodo) as $r) {
                if ((int)$r['ya_tiene']) { $omitidas++; continue; }
                $c = self::cuota($r, $periodo);
                $monto = $c['monto'];
                if ($monto <= 0) { $omitidas++; continue; }
                $concepto = ($c['proporcional'] ? "Cuota proporcional de {$mes} ({$c['dias']} de {$c['dias_mes']} días, desde su ingreso)" : "Cuota de {$mes}") . ': ' .
                    ($r['nombre_plan'] ?: 'sin plan') . ((float)$r['costo_habitacion'] > 0 ? " + habitación {$r['tipo_habitacion']}" : '');
                $this->model->cargarCuota((int)$r['id_residente'], $periodo, $concepto, $monto, Auth::id());
                $generadas++; $total += $monto;
            }
            Bitacora::registrar('CUOTAS_GENERADAS', 'cargos_familiares', ['periodo' => $periodo, 'generadas' => $generadas, 'omitidas' => $omitidas, 'total' => $total]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        if (!$generadas) {
            return ['generadas' => 0, 'omitidas' => $omitidas, 'mensaje' => "Las cuotas de {$mes} ya estaban generadas. No se duplicó ninguna."];
        }
        return ['generadas' => $generadas, 'omitidas' => $omitidas, 'total' => round($total, 2),
                'mensaje' => "Se generaron {$generadas} cuota(s) de {$mes} por Q " . number_format($total, 2) .
                    ($omitidas ? " ({$omitidas} ya la tenían)." : '.')];
    }

    /* ---------- Fundación ---------- */

    public function deudaFundacion(): array {
        $filas = $this->model->deudaFundacion();
        $tot = ['adeudado' => 0, 'pagado' => 0, 'saldo' => 0];
        foreach ($filas as $f) {
            $tot['adeudado'] += $f['adeudado']; $tot['pagado'] += $f['pagado']; $tot['saldo'] += max(0, (float)$f['saldo']);
        }
        return ['citas' => $filas, 'totales' => array_map(fn($v) => round($v, 2), $tot), 'pagos' => $this->model->pagosFundacion()];
    }

    public function pagarFundacion(array $in): array {
        $idVisita = Http::entero($in, 'id_visita', true, 1, null, 'Cita');
        $monto = round(Http::numero($in, 'monto', true, 0.01, 1000000, 'Monto (Q)'), 2);
        $fecha = Http::fecha($in, 'fecha', false, 'Fecha del pago') ?? date('Y-m-d');
        if ($fecha > date('Y-m-d')) throw new HttpError('La fecha del pago no puede ser futura.', 422);
        $metodo = Http::opcion($in, 'metodo', Caja::METODOS_FUNDACION, null, 'Forma de pago');
        $ref = Http::texto($in, 'referencia', false, 60, 'No. de boleta o referencia');
        if ($metodo !== 'Efectivo' && !$ref) throw new HttpError("Indique el número de boleta o referencia del pago con {$metodo}.", 422);

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $db->prepare('SELECT id_visita FROM visitas_medicas WHERE id_visita = ? FOR UPDATE')->execute([$idVisita]);
            $c = $this->model->deudaVisita($idVisita);
            if (!$c) throw new HttpError('La cita no existe.', 404);
            if ((float)$c['saldo'] <= 0) throw new HttpError('Esta cita ya está completamente cancelada a la fundación.', 409);
            if ($monto > (float)$c['saldo'] + 0.001) {
                throw new HttpError('El pago (Q ' . number_format($monto, 2) . ') es mayor que lo pendiente de esta cita (Q ' . number_format($c['saldo'], 2) . ').', 422);
            }
            $id = $this->model->registrarPagoFundacion(['id_visita' => $idVisita, 'monto' => $monto, 'fecha' => $fecha, 'metodo' => $metodo,
                'referencia' => $ref, 'id_usuario' => Auth::id()]);
            $nuevo = round((float)$c['saldo'] - $monto, 2);
            Bitacora::registrar('PAGO_FUNDACION', 'pagos_fundacion', ['id' => $id, 'visita' => $idVisita,
                'residente' => "{$c['residente_nombre']} {$c['residente_apellido']}", 'monto' => $monto, 'saldo_nuevo' => $nuevo]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'saldo' => $nuevo,
                'mensaje' => $nuevo <= 0 ? 'Pago registrado. La cita quedó completamente cancelada.' : 'Abono registrado. Falta Q ' . number_format($nuevo, 2) . ' para cancelar la cita.'];
    }

    /* ---------- Donaciones ---------- */

    public function donaciones(array $in): array {
        [$desde, $hasta] = $this->rango($in);
        $lista = $this->model->donaciones($desde, $hasta);
        $porTipo = [];
        foreach ($lista as $d) {
            $k = $d['tipo_donante'];
            if ((int)$d['anulado']) continue;
            $porTipo[$k] = ($porTipo[$k] ?? 0) + ($d['tipo_donacion'] === 'Monetaria' ? (float)$d['monto'] : 0);
        }
        return ['donaciones' => $lista, 'por_tipo' => $porTipo, 'tipos_donante' => Caja::TIPOS_DONANTE, 'tipos_donacion' => Caja::TIPOS_DONACION];
    }

    public function registrarDonacion(array $in): array {
        $d = [
            'tipo_donante' => Http::opcion($in, 'tipo_donante', Caja::TIPOS_DONANTE, null, 'Tipo de donante'),
            'donante' => Http::texto($in, 'donante', true, 150, 'Donante'),
            'tipo_donacion' => Http::opcion($in, 'tipo_donacion', Caja::TIPOS_DONACION, 'Monetaria', 'Tipo de donación'),
            'monto' => round(Http::numero($in, 'monto', true, 0.01, 10000000, 'Monto o valor estimado (Q)'), 2),
            'descripcion' => Http::texto($in, 'descripcion', false, 255, 'Descripción'),
            'fecha' => Http::fecha($in, 'fecha', false, 'Fecha') ?? date('Y-m-d'),
            'comprobante' => Http::texto($in, 'comprobante', false, 60, 'No. de recibo'),
            'id_usuario' => Auth::id(),
        ];
        if ($d['fecha'] > date('Y-m-d')) throw new HttpError('La fecha de la donación no puede ser futura.', 422);
        if ($d['tipo_donacion'] === 'En especie' && !$d['descripcion']) throw new HttpError('Describa qué se donó (por ejemplo: 20 cajas de pañales).', 422);
        $id = $this->model->registrarDonacion($d);
        Bitacora::registrar('DONACION', 'donaciones', ['id' => $id] + $d);
        return ['id' => $id, 'mensaje' => 'Donación registrada.'];
    }

    /* ---------- Gastos ---------- */

    public function gastos(array $in): array {
        [$desde, $hasta] = $this->rango($in);
        $lista = $this->model->gastos($desde, $hasta);
        $porCat = [];
        foreach ($lista as $g) if (!(int)$g['anulado']) $porCat[$g['categoria']] = ($porCat[$g['categoria']] ?? 0) + (float)$g['monto'];
        arsort($porCat);
        return ['gastos' => $lista, 'por_categoria' => $porCat, 'categorias' => Caja::CATEGORIAS_GASTO];
    }

    public function registrarGasto(array $in): array {
        $d = [
            'categoria' => Http::opcion($in, 'categoria', Caja::CATEGORIAS_GASTO, null, 'Categoría'),
            'descripcion' => Http::texto($in, 'descripcion', true, 255, 'Descripción'),
            'monto' => round(Http::numero($in, 'monto', true, 0.01, 10000000, 'Monto (Q)'), 2),
            'fecha' => Http::fecha($in, 'fecha', false, 'Fecha') ?? date('Y-m-d'),
            'comprobante' => Http::texto($in, 'comprobante', false, 60, 'No. de factura'),
            'id_usuario' => Auth::id(),
        ];
        if ($d['fecha'] > date('Y-m-d')) throw new HttpError('La fecha del gasto no puede ser futura.', 422);
        $id = $this->model->registrarGasto($d);
        Bitacora::registrar('GASTO', 'gastos', ['id' => $id] + $d);
        return ['id' => $id, 'mensaje' => 'Gasto registrado.'];
    }

    /**
     * Cuota del mes. ¿Y si el residente ingresó a mitad de mes? Se cobra solo la parte del mes
     * desde su fecha de ingreso (cuota proporcional). Quien ingresa el día 1 paga el mes completo.
     */
    private static function cuota(array $r, string $periodo): array {
        $completa = round((float)$r['costo_plan'] + (float)$r['costo_habitacion'], 2);
        $diasMes = (int)date('t', strtotime($periodo . '-01'));
        $ingreso = (string)($r['fecha_ingreso'] ?? '');
        if ($ingreso !== '' && substr($ingreso, 0, 7) === $periodo && (int)substr($ingreso, 8, 2) > 1) {
            $dias = $diasMes - (int)substr($ingreso, 8, 2) + 1;
            return ['monto' => round($completa * $dias / $diasMes, 2), 'cuota_completa' => $completa,
                    'proporcional' => true, 'dias' => $dias, 'dias_mes' => $diasMes];
        }
        return ['monto' => $completa, 'cuota_completa' => $completa, 'proporcional' => false, 'dias' => $diasMes, 'dias_mes' => $diasMes];
    }

    /* ---------- Anulación ---------- */

    /** Un pago, donación o gasto equivocado se anula con motivo: deja de contar en saldos y reportes, pero no se borra */
    public function anular(array $in): array {
        $tipo = Http::opcion($in, 'tipo', array_keys(Caja::ANULABLES), null, 'Tipo de registro');
        $id = Http::entero($in, 'id', true, 1, null, 'Registro');
        $motivo = Http::texto($in, 'motivo', true, 255, 'Motivo de la anulación');
        if (mb_strlen($motivo) < 10) throw new HttpError('Explique el motivo de la anulación (al menos 10 caracteres).', 422);
        $nombres = ['pago' => 'El pago', 'pago_fundacion' => 'El pago a la fundación', 'donacion' => 'La donación', 'gasto' => 'El gasto'];
        $anulado = $tipo === 'donacion' ? 'anulada' : 'anulado';

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $reg = $this->model->registroAnulable($tipo, $id);
            if (!$reg) throw new HttpError('El registro no existe.', 404);
            if ((int)$reg['anulado']) throw new HttpError("{$nombres[$tipo]} ya estaba {$anulado}.", 409);
            $this->model->anular($tipo, $id, $motivo, Auth::id());
            Bitacora::registrar('ANULACION', Caja::ANULABLES[$tipo][0], ['id' => $id, 'monto' => $reg['monto'], 'fecha' => $reg['fecha'], 'motivo' => $motivo]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['mensaje' => "{$nombres[$tipo]} de Q " . number_format((float)$reg['monto'], 2) . " quedó {$anulado} y ya no cuenta en saldos ni reportes. Sigue visible, tachado y con el motivo."];
    }

    /* ---------- Utilidades ---------- */

    private function periodo(array $in): string {
        $p = (string)($in['periodo'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p)) throw new HttpError('El mes no es válido (use AAAA-MM).', 422);
        return $p;
    }

    /** Rango de fechas; por defecto el mes actual cuando $porDefecto */
    private function rango(array $in, bool $porDefecto = false): array {
        $desde = Http::fecha($in, 'desde', false, 'Desde');
        $hasta = Http::fecha($in, 'hasta', false, 'Hasta');
        if ($porDefecto) {
            $desde ??= date('Y-m-01');
            $hasta ??= date('Y-m-t');
        }
        if ($desde && $hasta && $desde > $hasta) throw new HttpError('La fecha inicial es posterior a la final.', 422);
        return [$desde, $hasta];
    }

    private function nombreMes(string $periodo): string {
        $m = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $m[(int)substr($periodo, 5, 2) - 1] . ' ' . substr($periodo, 0, 4);
    }
}
