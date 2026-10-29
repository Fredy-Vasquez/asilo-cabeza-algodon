<?php
// src/Controllers/FamiliarController.php

require_once __DIR__ . '/../Models/Familiar.php';
require_once __DIR__ . '/../Core/Bitacora.php';

class FamiliarController {
    private Familiar $model;

    public function __construct() {
        $this->model = new Familiar();
    }

    public function listar(): array {
        return $this->model->listar();
    }

    public function guardar(array $in): array {
        $id = Http::entero($in, 'id_familiar');
        $d = [
            'nombre'       => Http::nombrePersona($in, 'nombre', true, 100, 'Nombre'),
            'apellido'     => Http::nombrePersona($in, 'apellido', true, 100, 'Apellidos'),
            'telefono'     => Http::telefono(isset($in['telefono']) ? (string)$in['telefono'] : null, true, 'teléfono'),
            'email'        => Http::texto($in, 'email', false, 100, 'Correo'),
            'parentesco'   => Http::texto($in, 'parentesco', true, 50, 'Parentesco'),
            'documento_id' => Http::texto($in, 'documento_id', true, 30, 'DPI'),
            'direccion'    => Http::texto($in, 'direccion', false, 500, 'Dirección'),
        ];
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            throw new HttpError('El correo del familiar no tiene un formato válido.', 422);
        }
        $d['documento_id'] = self::validarDpi($d['documento_id'], $id ? ($this->model->obtener($id)['documento_id'] ?? null) : null);
        if ($this->model->existeDocumento($d['documento_id'], $id)) {
            throw new HttpError('Ya existe un familiar registrado con ese DPI.', 409);
        }

        if ($id) {
            if (!$this->model->obtener($id)) throw new HttpError('El familiar no existe.', 404);
            $this->model->actualizar($id, $d);
            Bitacora::registrar('ACTUALIZA_FAMILIAR', 'familiares', ['id' => $id] + $d);
            return ['id' => $id, 'mensaje' => 'Familiar actualizado.'];
        }
        $id = $this->model->crear($d);
        Bitacora::registrar('REGISTRO_FAMILIAR', 'familiares', ['id' => $id] + $d);
        return ['id' => $id, 'mensaje' => 'Familiar registrado.'];
    }

    /** El DPI se valida completo al registrarlo o cambiarlo; uno ya guardado que no se modifica se respeta */
    public static function validarDpi(?string $dpi, ?string $anterior): ?string {
        if ($dpi === null) return null;
        $limpio = preg_replace('/[\s-]/', '', $dpi);
        if ($anterior !== null && $limpio === $anterior) return $anterior;
        return Http::dpi($dpi);
    }

    public function eliminar(array $in): array {
        $id = Http::entero($in, 'id_familiar', true);
        $a = $this->model->obtener($id);
        if (!$a) throw new HttpError('El familiar no existe.', 404);
        if ($this->model->totalResidentes($id) > 0) {
            throw new HttpError('No se puede eliminar: el familiar tiene residentes asociados. Asigne otro familiar responsable primero.', 409);
        }
        $this->model->eliminar($id);
        Bitacora::registrar('ELIMINA_FAMILIAR', 'familiares', $a);
        return ['mensaje' => 'Familiar eliminado.'];
    }
}
