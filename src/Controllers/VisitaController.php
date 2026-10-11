<?php
// src/Controllers/VisitaController.php
// CU: El médico especialista recibe al interno, revisa la solicitud y la ficha médica,
//     registra diagnóstico y observaciones, solicita exámenes y receta medicamentos.

require_once __DIR__ . '/../Models/SolicitudMedica.php';
require_once __DIR__ . '/../Models/VisitaMedica.php';
require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Models/FichaMedica.php';
require_once __DIR__ . '/../Models/EvaluacionMedica.php';
require_once __DIR__ . '/../Models/Catalogos.php';
require_once __DIR__ . '/../Models/CuentaFamiliar.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class VisitaController {
    private VisitaMedica $model;
    private SolicitudMedica $solicitudes;

    public function __construct() {
        $this->model = new VisitaMedica();
        $this->solicitudes = new SolicitudMedica();
    }

    /** Todo lo que el médico necesita ver: solicitud, visita, ficha e historial */
    public function detalle(array $in): array {
        $s = $this->solicitudes->obtener(Http::entero($in, 'id_solicitud', true));
        if (!$s) throw new HttpError('La solicitud no existe.', 404);
        if (Auth::tieneRol([ROL_ESPECIALISTA]) && (int)$s['id_medico_asignado'] !== Auth::id()) {
            throw new HttpError('Esta cita está asignada a otro médico.', 403);
        }
        $idRes = (int)$s['id_residente'];
        $ficha = new FichaMedica();
        $cat = new Catalogos();
        $historial = array_values(array_filter($this->model->historial($idRes), fn($v) => (int)$v['id_solicitud'] !== (int)$s['id_solicitud']));
        return [
            'solicitud' => $s,
            'visita' => $this->model->porSolicitud((int)$s['id_solicitud']),
            'residente' => (new Residente())->obtener($idRes),
            'padecimientos' => $ficha->padecimientos($idRes),
            'permanentes' => $ficha->permanentes($idRes),
            'evaluaciones' => array_slice((new EvaluacionMedica())->porResidente($idRes), 0, 5),
            'historial' => $historial,
            'catalogo_examenes' => $cat->examenes(),
            'catalogo_medicamentos' => $cat->medicamentos(),
            'descuento_pct' => $cat->descuentoFundacion(),
        ];
    }

    public function iniciar(array $in): array {
        $id = Http::entero($in, 'id_solicitud', true);
        $db = Database::conn();
        $db->beginTransaction();
        try {
            $s = $this->solicitudes->obtener($id, true);
            if (!$s) throw new HttpError('La solicitud no existe.', 404);
            $this->verificarMedico($s);
            if ($s['estado'] !== 'Programada') throw new HttpError("La solicitud está {$s['estado']}; solo se atienden citas programadas.", 409);
            if ($s['id_visita']) throw new HttpError('La visita ya fue iniciada.', 409);
            $idVisita = $this->model->crear([
                'id_solicitud' => $id, 'id_residente' => $s['id_residente'], 'id_medico' => $s['id_medico_asignado'],
                'motivo' => $s['motivo'], 'costo_consulta' => $s['costo_consulta'],
                'descuento_pct' => (new Catalogos())->descuentoFundacion(),
            ]);
            Bitacora::registrar('VISITA_INICIADA', 'visitas_medicas', ['id' => $idVisita, 'solicitud' => $id, 'residente' => "{$s['residente_nombre']} {$s['residente_apellido']}"]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id_visita' => $idVisita, 'mensaje' => 'Visita iniciada. La solicitud ya es parte de la ficha médica.'];
    }

    public function guardar(array $in): array {
        $v = $this->visitaEditable(Http::entero($in, 'id_visita', true));
        $diag = Http::texto($in, 'diagnostico', false, 4000, 'Diagnóstico');
        $obs = Http::texto($in, 'observaciones', false, 4000, 'Observaciones');
        $this->model->guardarNotas((int)$v['id_visita'], $diag, $obs);
        Bitacora::registrar('VISITA_NOTAS', 'visitas_medicas', ['id' => $v['id_visita']]);
        return ['mensaje' => 'Notas guardadas.'];
    }

    public function agregarExamen(array $in): array {
        $v = $this->visitaEditable(Http::entero($in, 'id_visita', true));
        $idExamen = Http::entero($in, 'id_examen', true, 1, null, 'Examen');
        $ex = null;
        foreach ((new Catalogos())->examenes() as $e) if ((int)$e['id_examen'] === $idExamen) $ex = $e;
        if (!$ex) throw new HttpError('Seleccione un examen del catálogo.', 422);
        foreach ($this->model->examenes((int)$v['id_visita']) as $ya) {
            if ((int)$ya['id_examen'] === $idExamen) throw new HttpError("«{$ex['nombre']}» ya está solicitado en esta visita.", 409);
        }
        $id = $this->model->agregarExamen((int)$v['id_visita'], [
            'id_examen' => $idExamen, 'indicaciones' => Http::texto($in, 'indicaciones', false, 255, 'Indicaciones'),
            'precio' => $ex['precio'], 'descuento_pct' => $v['descuento_pct'],
        ]);
        Bitacora::registrar('VISITA_EXAMEN', 'examenes_visita', ['id' => $id, 'visita' => $v['id_visita'], 'examen' => $ex['nombre']]);
        return ['mensaje' => "Examen solicitado: {$ex['nombre']}. El laboratorio ya puede verlo."];
    }

    public function quitarExamen(array $in): array {
        $e = $this->model->obtenerExamen(Http::entero($in, 'id_examen_visita', true));
        if (!$e) throw new HttpError('El examen no existe.', 404);
        $this->visitaEditable((int)$e['id_visita']);
        if ($e['estado'] !== 'Solicitado') throw new HttpError('El laboratorio ya registró el resultado; no se puede quitar.', 409);
        $this->model->quitarExamen((int)$e['id_examen_visita']);
        Bitacora::registrar('VISITA_QUITA_EXAMEN', 'examenes_visita', $e);
        return ['mensaje' => 'Examen quitado.'];
    }

    public function agregarReceta(array $in): array {
        $v = $this->visitaEditable(Http::entero($in, 'id_visita', true));
        $med = (new Catalogos())->medicamento(Http::entero($in, 'id_medicamento', true, 1, null, 'Medicamento'));
        if (!$med || !$med['activo']) throw new HttpError('Seleccione un medicamento del catálogo.', 422);
        $id = $this->model->agregarReceta((int)$v['id_visita'], [
            'id_medicamento' => $med['id_medicamento'],
            'cantidad' => Http::entero($in, 'cantidad', true, 1, 100, 'Cantidad'),
            'indicaciones' => Http::texto($in, 'indicaciones', true, 255, 'Cómo tomarlo'),
            'duracion_dias' => Http::entero($in, 'duracion_dias', false, 1, 365, 'Duración (días)'),
            'precio_unitario' => $med['precio'], 'descuento_pct' => $v['descuento_pct'],
        ]);
        Bitacora::registrar('VISITA_RECETA', 'recetas_visita', ['id' => $id, 'visita' => $v['id_visita'], 'medicamento' => $med['nombre_comercial']]);
        return ['mensaje' => "Medicamento recetado: {$med['nombre_comercial']}. Farmacia ya puede entregarlo."];
    }

    public function quitarReceta(array $in): array {
        $r = $this->model->obtenerReceta(Http::entero($in, 'id_receta', true));
        if (!$r) throw new HttpError('La receta no existe.', 404);
        $this->visitaEditable((int)$r['id_visita']);
        if ($r['estado'] !== 'Pendiente') throw new HttpError('Farmacia ya entregó este medicamento; no se puede quitar.', 409);
        $this->model->quitarReceta((int)$r['id_receta']);
        Bitacora::registrar('VISITA_QUITA_RECETA', 'recetas_visita', $r);
        return ['mensaje' => 'Medicamento quitado de la receta.'];
    }

    /** Cierra la visita, marca la solicitud como atendida y carga la consulta a la cuenta del familiar */
    public function finalizar(array $in): array {
        $id = Http::entero($in, 'id_visita', true);
        $diag = Http::texto($in, 'diagnostico', true, 4000, 'Diagnóstico');
        $obs = Http::texto($in, 'observaciones', false, 4000, 'Observaciones');

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $v = $this->visitaEditable($id, true);
            $this->model->guardarNotas($id, $diag, $obs);
            $this->model->finalizar($id);
            $this->solicitudes->cambiarEstado((int)$v['id_solicitud'], 'Atendida');
            $cargo = (new CuentaFamiliar())->cargar(
                (int)$v['id_residente'], 'Consulta', "Consulta de {$v['especialidad']} con {$v['medico']}",
                (float)$v['costo_consulta'], (float)$v['descuento_pct'], $id, null, Auth::id()
            );
            Bitacora::registrar('VISITA_FINALIZADA', 'visitas_medicas', ['id' => $id, 'cargo_consulta' => $cargo['neto']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['mensaje' => 'Visita finalizada. La consulta se cargó a la cuenta del familiar con el descuento de la fundación.'];
    }

    private function verificarMedico(array $s): void {
        if (!Auth::tieneRol([ROL_ADMIN]) && (int)$s['id_medico_asignado'] !== Auth::id()) {
            throw new HttpError('Solo el médico asignado puede atender esta cita.', 403);
        }
    }

    private function visitaEditable(int $idVisita, bool $bloquear = false): array {
        $v = $this->model->obtener($idVisita, $bloquear);
        if (!$v) throw new HttpError('La visita no existe.', 404);
        if (!Auth::tieneRol([ROL_ADMIN]) && (int)$v['id_medico'] !== Auth::id()) {
            throw new HttpError('Solo el médico que atiende puede modificar esta visita.', 403);
        }
        if ($v['estado'] === 'Finalizada') throw new HttpError('La visita ya fue finalizada.', 409);
        return $v;
    }
}
