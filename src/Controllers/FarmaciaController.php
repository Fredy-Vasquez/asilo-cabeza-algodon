<?php
// src/Controllers/FarmaciaController.php
// CU: Farmacia entrega los medicamentos recetados, se descuentan del inventario y el costo
//     se carga a la cuenta del familiar con el descuento de la fundación. Administra el inventario.

require_once __DIR__ . '/../Models/Farmacia.php';
require_once __DIR__ . '/../Models/CuentaFamiliar.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class FarmaciaController {
    private Farmacia $model;

    public function __construct() {
        $this->model = new Farmacia();
    }

    public function listar(array $in): array {
        $estado = in_array($in['estado'] ?? '', Farmacia::ESTADOS, true) ? $in['estado'] : null;
        return [
            'recetas' => $this->model->listar($estado),
            'pendientes' => $this->model->contarPendientes(),
            'stock_bajo' => $this->model->stockBajo(),
        ];
    }

    public function entregar(array $in): array {
        $id = Http::entero($in, 'id_receta', true);
        $db = Database::conn();
        $db->beginTransaction();
        try {
            $r = $this->model->obtener($id, true);
            if (!$r) throw new HttpError('La receta no existe.', 404);
            if ($r['estado'] === 'Entregado') throw new HttpError("Este medicamento ya fue entregado por {$r['farmaceutico']}.", 409);
            $med = $this->model->medicamento((int)$r['id_medicamento'], true);
            $cant = (int)$r['cantidad'];
            if ((int)$med['stock'] < $cant) {
                throw new HttpError("No hay suficiente {$med['nombre_comercial']}: se necesitan {$cant} y hay {$med['stock']} en inventario. Registre un ingreso de inventario.", 409);
            }
            $this->model->cambiarStock((int)$med['id_medicamento'], -$cant);
            $this->model->marcarEntregada($id, Auth::id());
            $cargo = (new CuentaFamiliar())->cargar(
                (int)$r['id_residente'], 'Medicamento', "{$med['nombre_comercial']} x {$cant}",
                round((float)$r['precio_unitario'] * $cant, 2), (float)$r['descuento_pct'], (int)$r['id_visita'], null, Auth::id()
            );
            $restante = (int)$med['stock'] - $cant;
            Bitacora::registrar('FARMACIA_ENTREGA', 'recetas_visita', [
                'id' => $id, 'medicamento' => $med['nombre_comercial'], 'cantidad' => $cant,
                'residente' => "{$r['residente_nombre']} {$r['residente_apellido']}", 'stock_restante' => $restante, 'cargo' => $cargo['neto'],
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $msg = "Entregado. Se cargaron Q " . number_format($cargo['neto'], 2) . ' a la cuenta del familiar.';
        if ($restante <= (int)$med['stock_minimo']) $msg .= " Atención: quedan {$restante} de {$med['nombre_comercial']} (mínimo {$med['stock_minimo']}).";
        return ['mensaje' => $msg, 'cargo' => $cargo, 'stock_restante' => $restante];
    }

    public function inventario(): array {
        return ['medicamentos' => $this->model->medicamentos(), 'stock_bajo' => $this->model->stockBajo()];
    }

    public function guardarMedicamento(array $in): array {
        $id = Http::entero($in, 'id_medicamento', false, 1);
        $d = [
            'nombre_comercial' => Http::texto($in, 'nombre_comercial', true, 100, 'Nombre comercial'),
            'componente_activo' => Http::texto($in, 'componente_activo', true, 100, 'Componente activo'),
            'presentacion' => Http::texto($in, 'presentacion', true, 60, 'Presentación'),
            'precio' => round(Http::numero($in, 'precio', true, 0, 50000, 'Precio (Q)'), 2),
            'stock_minimo' => Http::entero($in, 'stock_minimo', true, 0, 10000, 'Stock mínimo'),
            'stock' => $id ? null : Http::entero($in, 'stock', true, 0, 100000, 'Existencia inicial'),
            'activo' => !isset($in['activo']) || filter_var($in['activo'], FILTER_VALIDATE_BOOLEAN),
        ];
        if ($id && !$this->model->medicamento($id)) throw new HttpError('El medicamento no existe.', 404);
        if ($this->model->existeNombre($d['nombre_comercial'], $id)) throw new HttpError("Ya existe «{$d['nombre_comercial']}» en el catálogo.", 409);
        $nuevo = $this->model->guardarMedicamento($id, $d);
        Bitacora::registrar($id ? 'MEDICAMENTO_EDITADO' : 'MEDICAMENTO_CREADO', 'medicamentos', ['id' => $nuevo] + $d);
        return ['id' => $nuevo, 'mensaje' => $id ? 'Medicamento actualizado.' : 'Medicamento agregado al inventario.'];
    }

    /** Ingreso de inventario (compra o donación de medicamentos) */
    public function ingreso(array $in): array {
        $id = Http::entero($in, 'id_medicamento', true, 1, null, 'Medicamento');
        $cant = Http::entero($in, 'cantidad', true, 1, 100000, 'Cantidad que ingresa');
        $nota = Http::texto($in, 'nota', false, 255, 'Nota');
        $db = Database::conn();
        $db->beginTransaction();
        try {
            $med = $this->model->medicamento($id, true);
            if (!$med) throw new HttpError('El medicamento no existe.', 404);
            $this->model->cambiarStock($id, $cant);
            Bitacora::registrar('INVENTARIO_INGRESO', 'medicamentos', [
                'id' => $id, 'medicamento' => $med['nombre_comercial'], 'cantidad' => $cant,
                'stock_anterior' => (int)$med['stock'], 'stock_nuevo' => (int)$med['stock'] + $cant, 'nota' => $nota,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['mensaje' => "Ingreso registrado. Existencia de {$med['nombre_comercial']}: " . ((int)$med['stock'] + $cant) . '.'];
    }
}
