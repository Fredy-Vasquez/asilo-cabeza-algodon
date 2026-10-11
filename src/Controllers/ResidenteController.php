<?php
// src/Controllers/ResidenteController.php
// CU-02 Registrar ingreso de nuevo residente (transacción ACID, RNF02).

require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Models/Habitacion.php';
require_once __DIR__ . '/../Models/Familiar.php';
require_once __DIR__ . '/../Models/EvaluacionMedica.php';
require_once __DIR__ . '/../Models/FichaMedica.php';
require_once __DIR__ . '/../Models/VisitaMedica.php';
require_once __DIR__ . '/../Models/SolicitudMedica.php';
require_once __DIR__ . '/../Core/Bitacora.php';

class ResidenteController {
    private Residente $model;
    private Habitacion $habitaciones;

    public function __construct() {
        $this->model = new Residente();
        $this->habitaciones = new Habitacion();
    }

    public function listar(array $in): array {
        $estado = $in['estado'] ?? null;
        if ($estado !== null && $estado !== '' && !in_array($estado, Residente::ESTADOS, true)) $estado = null;
        return $this->model->listar($estado ?: null);
    }

    /** Expediente completo: datos, familiar, habitación, ficha médica y evaluaciones */
    public function expediente(array $in): array {
        $id = Http::entero($in, 'id_residente', true);
        $r = $this->model->obtener($id);
        if (!$r) throw new HttpError('El residente no existe.', 404);
        $ficha = new FichaMedica();
        $r['padecimientos'] = $ficha->padecimientos($id);
        $r['medicamentos_permanentes'] = $ficha->permanentes($id);
        $r['evaluaciones'] = (new EvaluacionMedica())->porResidente($id);
        $r['visitas'] = (new VisitaMedica())->historial($id);
        $r['solicitudes_abiertas'] = array_values(array_filter((new SolicitudMedica())->listar(['id_residente' => $id]), fn($x) => in_array($x['estado'], ['Pendiente', 'Programada'], true)));
        return $r;
    }

    public function guardar(array $in): array {
        $id = Http::entero($in, 'id_residente');
        $d = [
            'nombre'           => Http::texto($in, 'nombre', true, 100, 'Nombre'),
            'apellido'         => Http::texto($in, 'apellido', true, 100, 'Apellidos'),
            'documento_id'     => Http::texto($in, 'documento_id', false, 30, 'DPI'),
            'fecha_nacimiento' => Http::fecha($in, 'fecha_nacimiento', true, 'Fecha de nacimiento'),
            'genero'           => Http::opcion($in, 'genero', Residente::GENEROS, null, 'Género'),
            'fecha_ingreso'    => Http::fecha($in, 'fecha_ingreso', false, 'Fecha de ingreso') ?? date('Y-m-d'),
            'id_familiar'     => Http::entero($in, 'id_familiar', true, 1, null, 'Familiar responsable'),
            'id_habitacion'    => Http::entero($in, 'id_habitacion', true, 1, null, 'Habitación'),
            'id_plan'          => Http::entero($in, 'id_plan', false, 1, null, 'Plan'),
            'motivo_ingreso'   => Http::texto($in, 'motivo_ingreso', true, 2000, 'Motivo de ingreso'),
            'observaciones'    => Http::texto($in, 'observaciones', false, 2000, 'Observaciones'),
        ];
        if ($d['documento_id']) $d['documento_id'] = preg_replace('/\s+/', '', $d['documento_id']);

        $edad = (new DateTime($d['fecha_nacimiento']))->diff(new DateTime())->y;
        if ($d['fecha_nacimiento'] > date('Y-m-d')) throw new HttpError('La fecha de nacimiento no puede ser futura.', 422);
        if ($edad < 50 || $edad > 120) throw new HttpError("La edad calculada ({$edad} años) no es válida para un residente.", 422);
        if ($d['fecha_ingreso'] > date('Y-m-d')) throw new HttpError('La fecha de ingreso no puede ser futura.', 422);

        if (!(new Familiar())->obtener($d['id_familiar'])) throw new HttpError('Seleccione un familiar responsable válido.', 422);
        if ($this->model->existeDocumento($d['documento_id'], $id)) {
            throw new HttpError('Ya existe un residente con ese DPI.', 409);
        }

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $anterior = $id ? $this->model->obtener($id) : null;
            if ($id && !$anterior) throw new HttpError('El residente no existe.', 404);
            $cambiaHabitacion = !$anterior || (int)$anterior['id_habitacion'] !== $d['id_habitacion'];

            // Bloqueo de la fila de la habitación para evitar sobreasignar la misma cama
            $hab = $this->habitaciones->obtener($d['id_habitacion'], true);
            if (!$hab) throw new HttpError('La habitación seleccionada no existe.', 422);
            if ($cambiaHabitacion) {
                if ($hab['estado'] === 'mantenimiento') {
                    throw new HttpError("La habitación {$hab['numero_habitacion']} está en mantenimiento.", 409);
                }
                if ($this->habitaciones->ocupantes($d['id_habitacion']) >= (int)$hab['capacidad']) {
                    throw new HttpError("La habitación {$hab['numero_habitacion']} ya no tiene camas libres. Elija otra.", 409);
                }
            }

            if ($anterior) {
                $this->model->actualizar($id, $d);
                if ($cambiaHabitacion && $anterior['id_habitacion']) {
                    $this->habitaciones->recalcularEstado((int)$anterior['id_habitacion']);
                }
                $accion = 'ACTUALIZA_RESIDENTE';
                $mensaje = 'Expediente actualizado.';
            } else {
                $id = $this->model->crear($d);
                $accion = 'REGISTRO_RESIDENTE';
                $mensaje = "Residente registrado en la habitación {$hab['numero_habitacion']}.";
            }
            $this->habitaciones->recalcularEstado($d['id_habitacion']);
            Bitacora::registrar($accion, 'residentes', ['id' => $id, 'nombre' => "{$d['nombre']} {$d['apellido']}", 'habitacion' => $hab['numero_habitacion']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'mensaje' => $mensaje];
    }

    /** Alta (inactivo) libera la cama; hospitalizado la conserva */
    public function cambiarEstado(array $in): array {
        $id = Http::entero($in, 'id_residente', true);
        $estado = Http::opcion($in, 'estado', Residente::ESTADOS, null, 'Estado');

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $r = $this->model->obtener($id);
            if (!$r) throw new HttpError('El residente no existe.', 404);
            if ($estado !== 'inactivo' && $r['estado'] === 'inactivo') {
                throw new HttpError('Para reingresar a un residente dado de baja, edite su expediente y asígnele una habitación.', 409);
            }
            $liberar = $estado === 'inactivo';
            $this->model->cambiarEstado($id, $estado, $liberar);
            if ($r['id_habitacion']) $this->habitaciones->recalcularEstado((int)$r['id_habitacion']);
            Bitacora::registrar('CAMBIO_ESTADO_RESIDENTE', 'residentes', ['id' => $id, 'de' => $r['estado'], 'a' => $estado]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $textos = ['activo' => 'Residente marcado como activo.', 'hospitalizado' => 'Residente marcado como hospitalizado; conserva su cama.', 'inactivo' => 'Residente dado de baja; su cama quedó libre.'];
        return ['mensaje' => $textos[$estado]];
    }
}
