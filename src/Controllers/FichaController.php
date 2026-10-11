<?php
// src/Controllers/FichaController.php
// Ficha médica: psicopatologías, padecimientos y medicamentos permanentes del interno.

require_once __DIR__ . '/../Models/FichaMedica.php';
require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Models/Catalogos.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class FichaController {
    private FichaMedica $model;

    public function __construct() {
        $this->model = new FichaMedica();
    }

    public function catalogoMedicamentos(): array {
        return (new Catalogos())->medicamentos();
    }

    public function guardarPadecimiento(array $in): array {
        $id = Http::entero($in, 'id_padecimiento');
        $d = [
            'id_residente'      => Http::entero($in, 'id_residente', !$id, 1, null, 'Residente'),
            'tipo'              => Http::opcion($in, 'tipo', FichaMedica::TIPOS, null, 'Tipo'),
            'descripcion'       => Http::texto($in, 'descripcion', true, 255, 'Descripción'),
            'fecha_diagnostico' => Http::fecha($in, 'fecha_diagnostico', false, 'Fecha de diagnóstico'),
            'estado'            => Http::opcion($in, 'estado', FichaMedica::ESTADOS, 'Activo', 'Estado'),
            'notas'             => Http::texto($in, 'notas', false, 2000, 'Notas'),
            'id_usuario'        => Auth::id(),
        ];
        if ($d['fecha_diagnostico'] && $d['fecha_diagnostico'] > date('Y-m-d')) {
            throw new HttpError('La fecha de diagnóstico no puede ser futura.', 422);
        }
        if ($id) {
            $previo = $this->model->obtenerPadecimiento($id);
            if (!$previo) throw new HttpError('El registro no existe.', 404);
            $d['id_residente'] = (int)$previo['id_residente'];
        } elseif (!(new Residente())->obtener($d['id_residente'])) {
            throw new HttpError('El residente no existe.', 404);
        }
        $id = $this->model->guardarPadecimiento($id, $d);
        Bitacora::registrar('FICHA_PADECIMIENTO', 'padecimientos', ['id' => $id, 'residente' => $d['id_residente'], 'tipo' => $d['tipo'], 'descripcion' => $d['descripcion'], 'estado' => $d['estado']]);
        return ['id' => $id, 'mensaje' => 'Ficha médica actualizada.'];
    }

    public function eliminarPadecimiento(array $in): array {
        $id = Http::entero($in, 'id_padecimiento', true);
        $p = $this->model->obtenerPadecimiento($id);
        if (!$p) throw new HttpError('El registro no existe.', 404);
        $this->model->eliminarPadecimiento($id);
        Bitacora::registrar('FICHA_ELIMINA_PADECIMIENTO', 'padecimientos', $p);
        return ['mensaje' => 'Registro eliminado de la ficha.'];
    }

    public function guardarPermanente(array $in): array {
        $id = Http::entero($in, 'id_permanente');
        $d = [
            'id_residente'   => Http::entero($in, 'id_residente', !$id, 1, null, 'Residente'),
            'id_medicamento' => Http::entero($in, 'id_medicamento', true, 1, null, 'Medicamento'),
            'dosis'          => Http::texto($in, 'dosis', true, 100, 'Dosis'),
            'frecuencia'     => Http::texto($in, 'frecuencia', true, 100, 'Frecuencia'),
            'indicaciones'   => Http::texto($in, 'indicaciones', false, 500, 'Indicaciones'),
            'fecha_inicio'   => Http::fecha($in, 'fecha_inicio', false, 'Fecha de inicio') ?? date('Y-m-d'),
            'activo'         => (!isset($in['activo']) || $in['activo'] === true || $in['activo'] === '1' || $in['activo'] === 'true') ? 1 : 0,
            'id_usuario'     => Auth::id(),
        ];
        $med = (new Catalogos())->medicamento($d['id_medicamento']);
        if (!$med) throw new HttpError('Seleccione un medicamento del catálogo.', 422);
        if ($id) {
            $previo = $this->model->obtenerPermanente($id);
            if (!$previo) throw new HttpError('El registro no existe.', 404);
            $d['id_residente'] = (int)$previo['id_residente'];
        } elseif (!(new Residente())->obtener($d['id_residente'])) {
            throw new HttpError('El residente no existe.', 404);
        }
        $id = $this->model->guardarPermanente($id, $d);
        Bitacora::registrar('FICHA_MEDICAMENTO_PERMANENTE', 'medicamentos_permanentes', [
            'id' => $id, 'residente' => $d['id_residente'], 'medicamento' => $med['nombre_comercial'],
            'dosis' => $d['dosis'], 'frecuencia' => $d['frecuencia'], 'activo' => $d['activo'],
        ]);
        return ['id' => $id, 'mensaje' => $d['activo'] ? 'Medicamento guardado en la ficha.' : 'Medicamento suspendido.'];
    }
}
