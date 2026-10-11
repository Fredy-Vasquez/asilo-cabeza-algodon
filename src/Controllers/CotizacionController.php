<?php
// src/Controllers/CotizacionController.php
// RF03: cotización mensual = plan (planes_servicio) + habitación (habitaciones) + cuidados + extras − descuento.

require_once __DIR__ . '/../Models/PlanServicio.php';
require_once __DIR__ . '/../Models/Habitacion.php';
require_once __DIR__ . '/../Services/MicroservicioClient.php';
require_once __DIR__ . '/../Core/Bitacora.php';

class CotizacionController {
    /** Catálogo de servicios adicionales (Q / mes) */
    public const EXTRAS = [
        'fisioterapia' => ['nombre' => 'Fisioterapia (4 sesiones al mes)', 'precio' => 320.00],
        'insumos'      => ['nombre' => 'Insumos gerontológicos básicos', 'precio' => 50.00],
        'material'     => ['nombre' => 'Material médico especializado', 'precio' => 120.00],
        'dieta'        => ['nombre' => 'Dieta terapéutica personalizada', 'precio' => 100.00],
        'acompanante'  => ['nombre' => 'Acompañamiento a citas externas', 'precio' => 150.00],
    ];
    public const CUIDADOS = [
        'bajo'  => ['nombre' => 'Básico: independiente, supervisión general', 'recargo' => 0.00],
        'medio' => ['nombre' => 'Intermedio: ayuda en actividades diarias', 'recargo' => 100.00],
        'alto'  => ['nombre' => 'Especializado: dependencia total o monitoreo', 'recargo' => 250.00],
    ];

    public function catalogo(): array {
        $extras = [];
        foreach (self::EXTRAS as $clave => $e) $extras[] = ['clave' => $clave] + $e;
        $cuidados = [];
        foreach (self::CUIDADOS as $clave => $c) $cuidados[] = ['clave' => $clave] + $c;
        return [
            'planes' => (new PlanServicio())->listar(),
            'habitaciones' => (new Habitacion())->costosPorTipo(),
            'cuidados' => $cuidados,
            'extras' => $extras,
        ];
    }

    public function cotizar(array $in): array {
        $plan = (new PlanServicio())->obtener(Http::entero($in, 'id_plan', true, 1, null, 'Plan'));
        if (!$plan) throw new HttpError('Seleccione un plan de servicio válido.', 422);
        $tipo = Http::opcion($in, 'tipo_habitacion', Habitacion::TIPOS, null, 'Tipo de habitación');
        $nivel = Http::opcion($in, 'nivel_cuidados', array_keys(self::CUIDADOS), 'medio', 'Nivel de cuidados');
        $descuento = Http::numero($in, 'descuento_pct', false, 0, 50, 'Descuento (%)') ?? 0;

        $costoHab = 0.0;
        foreach ((new Habitacion())->costosPorTipo() as $h) {
            if ($h['tipo'] === $tipo) $costoHab = (float)$h['costo_adicional'];
        }

        $extras = [];
        foreach ((array)($in['extras'] ?? []) as $clave) {
            if (isset(self::EXTRAS[$clave])) $extras[] = self::EXTRAS[$clave];
        }

        $origen = 'microservicio';
        try {
            $r = (new MicroservicioClient())->cotizarEstancia((float)$plan['costo_base'], $costoHab, $nivel, $extras, $descuento);
        } catch (MicroservicioNoDisponible $e) {
            $origen = 'local';
            $recargo = self::CUIDADOS[$nivel]['recargo'];
            $totalExtras = array_sum(array_column($extras, 'precio'));
            $subtotal = (float)$plan['costo_base'] + $costoHab + $recargo + $totalExtras;
            $montoDesc = round($subtotal * $descuento / 100, 2);
            $r = [
                'costo_base' => (float)$plan['costo_base'], 'costo_habitacion' => $costoHab, 'recargo_cuidado' => $recargo,
                'total_extras' => $totalExtras, 'subtotal' => $subtotal, 'descuento_pct' => $descuento,
                'monto_descuento' => $montoDesc, 'monto_total' => round($subtotal - $montoDesc, 2),
            ];
        }

        Bitacora::registrar('COTIZACION', 'planes_servicio', [
            'plan' => $plan['nombre_plan'], 'habitacion' => $tipo, 'cuidados' => $nivel, 'total' => $r['monto_total'], 'origen' => $origen,
        ]);

        return $r + [
            'plan' => $plan['nombre_plan'],
            'tipo_habitacion' => $tipo,
            'nivel_cuidados' => self::CUIDADOS[$nivel]['nombre'],
            'extras' => $extras,
            'origen_calculo' => $origen,
            'aviso' => $origen === 'local' ? 'El microservicio cotizador no respondió; el cálculo se hizo en el servidor principal.' : null,
        ];
    }
}
