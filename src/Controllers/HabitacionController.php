<?php
// src/Controllers/HabitacionController.php

require_once __DIR__ . '/../Models/Habitacion.php';
require_once __DIR__ . '/../Core/Bitacora.php';

class HabitacionController {
    private Habitacion $model;

    public function __construct() {
        $this->model = new Habitacion();
    }

    public function listar(): array {
        return $this->model->listar();
    }

    public function disponibles(array $in): array {
        return $this->model->disponibles(Http::entero($in, 'incluir'));
    }

    public function guardar(array $in): array {
        $id = Http::entero($in, 'id_habitacion');
        $d = [
            'numero_habitacion' => Http::texto($in, 'numero_habitacion', true, 10, 'Número'),
            'piso'              => Http::entero($in, 'piso', true, 0, 20, 'Piso'),
            'tipo'              => Http::opcion($in, 'tipo', Habitacion::TIPOS, null, 'Tipo'),
            'capacidad'         => Http::entero($in, 'capacidad', true, 1, 10, 'Capacidad'),
            'costo_adicional'   => Http::numero($in, 'costo_adicional', false, 0, 100000, 'Costo adicional') ?? 0,
        ];
        $mantenimiento = !empty($in['mantenimiento']) && $in['mantenimiento'] !== 'false';

        if ($this->model->existeNumero($d['numero_habitacion'], $id)) {
            throw new HttpError('Ya existe una habitación con ese número.', 409);
        }

        $db = Database::conn();
        $db->beginTransaction();
        try {
            if ($id) {
                if (!$this->model->obtener($id, true)) throw new HttpError('La habitación no existe.', 404);
                $ocupantes = $this->model->ocupantes($id);
                if ($d['capacidad'] < $ocupantes) {
                    throw new HttpError("La habitación tiene {$ocupantes} residente(s); la capacidad no puede ser menor.", 409);
                }
                if ($mantenimiento && $ocupantes > 0) {
                    throw new HttpError('No se puede pasar a mantenimiento una habitación con residentes. Reubíquelos primero.', 409);
                }
                $d['estado'] = $mantenimiento ? 'mantenimiento' : 'disponible';
                $this->model->actualizar($id, $d);
                $this->model->recalcularEstado($id);
                Bitacora::registrar('ACTUALIZA_HABITACION', 'habitaciones', ['id' => $id] + $d);
                $msg = 'Habitación actualizada.';
            } else {
                $d['estado'] = $mantenimiento ? 'mantenimiento' : 'disponible';
                $id = $this->model->crear($d);
                Bitacora::registrar('REGISTRO_HABITACION', 'habitaciones', ['id' => $id] + $d);
                $msg = 'Habitación registrada.';
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'mensaje' => $msg];
    }

    public function eliminar(array $in): array {
        $id = Http::entero($in, 'id_habitacion', true);
        $h = $this->model->obtener($id);
        if (!$h) throw new HttpError('La habitación no existe.', 404);
        if ($this->model->ocupantes($id) > 0) {
            throw new HttpError('No se puede eliminar una habitación con residentes.', 409);
        }
        $this->model->eliminar($id);
        Bitacora::registrar('ELIMINA_HABITACION', 'habitaciones', $h);
        return ['mensaje' => 'Habitación eliminada.'];
    }
}
