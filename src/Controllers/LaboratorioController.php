<?php
// src/Controllers/LaboratorioController.php
// CU: El laboratorio recibe los exámenes que pidió el especialista, registra el resultado
//     y el costo del examen se carga a la cuenta del familiar con el descuento de la fundación.

require_once __DIR__ . '/../Models/Laboratorio.php';
require_once __DIR__ . '/../Models/CuentaFamiliar.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class LaboratorioController {
    private Laboratorio $model;

    public function __construct() {
        $this->model = new Laboratorio();
    }

    public function listar(array $in): array {
        $estado = in_array($in['estado'] ?? '', Laboratorio::ESTADOS, true) ? $in['estado'] : null;
        return [
            'examenes' => $this->model->listar($estado),
            'pendientes' => $this->model->contarPendientes(),
        ];
    }

    public function registrarResultado(array $in): array {
        $id = Http::entero($in, 'id_examen_visita', true);
        $resultado = Http::texto($in, 'resultado', true, 4000, 'Resultado');
        if (mb_strlen($resultado) < 3) throw new HttpError('Escriba el resultado del examen.', 422);

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $ex = $this->model->obtener($id, true);
            if (!$ex) throw new HttpError('El examen no existe.', 404);
            if ($ex['estado'] === 'Realizado') {
                throw new HttpError("El resultado de «{$ex['examen']}» ya fue registrado por {$ex['laboratorista']}.", 409);
            }
            $this->model->registrarResultado($id, $resultado, Auth::id());
            $cargo = (new CuentaFamiliar())->cargar(
                (int)$ex['id_residente'], 'Examen', "Examen: {$ex['examen']}",
                (float)$ex['precio'], (float)$ex['descuento_pct'], (int)$ex['id_visita'], null, Auth::id()
            );
            Bitacora::registrar('LAB_RESULTADO', 'examenes_visita', [
                'id' => $id, 'examen' => $ex['examen'],
                'residente' => "{$ex['residente_nombre']} {$ex['residente_apellido']}", 'cargo' => $cargo['neto'],
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return [
            'mensaje' => "Resultado registrado. Se cargaron Q " . number_format($cargo['neto'], 2) .
                " a la cuenta del familiar (descuento de Q " . number_format($cargo['descuento'], 2) . ').',
            'cargo' => $cargo,
        ];
    }

    public function catalogo(): array {
        return $this->model->catalogo();
    }

    public function guardarExamen(array $in): array {
        $id = Http::entero($in, 'id_examen', false, 1);
        $nombre = Http::texto($in, 'nombre', true, 120, 'Nombre del examen');
        $precio = Http::numero($in, 'precio', true, 0, 50000, 'Precio (Q)');
        $activo = !isset($in['activo']) || filter_var($in['activo'], FILTER_VALIDATE_BOOLEAN);
        if ($this->model->existeNombre($nombre, $id)) throw new HttpError("Ya existe un examen llamado «{$nombre}».", 409);
        $nuevo = $this->model->guardarExamen($id, $nombre, round($precio, 2), $activo);
        Bitacora::registrar($id ? 'EXAMEN_EDITADO' : 'EXAMEN_CREADO', 'examenes_catalogo', ['id' => $nuevo, 'nombre' => $nombre, 'precio' => $precio, 'activo' => $activo]);
        return ['id' => $nuevo, 'mensaje' => $id ? 'Examen actualizado. El nuevo precio aplica a las próximas solicitudes.' : 'Examen agregado al catálogo.'];
    }
}
