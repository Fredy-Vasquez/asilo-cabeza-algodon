<?php
// src/Controllers/FamiliarController.php

require_once __DIR__ . '/../Models/Familiar.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Services/Correo.php';

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
            $antes = $this->model->obtener($id);
            if (!$antes) throw new HttpError('El familiar no existe.', 404);
            $this->model->actualizar($id, $d);
            Bitacora::registrar('ACTUALIZA_FAMILIAR', 'familiares', ['id' => $id] + $d);
            $mensaje = 'Familiar actualizado.';
            // Si se registró o cambió su correo, se le confirma en la nueva dirección
            if ($d['email'] !== null && mb_strtolower((string)$antes['email']) !== mb_strtolower($d['email'])) {
                $mensaje .= self::avisoCorreo(self::correoBienvenida($id, $d, true));
            }
            return ['id' => $id, 'mensaje' => $mensaje];
        }
        $id = $this->model->crear($d);
        Bitacora::registrar('REGISTRO_FAMILIAR', 'familiares', ['id' => $id] + $d);
        $mensaje = 'Familiar registrado.';
        if ($d['email'] !== null) $mensaje .= self::avisoCorreo(self::correoBienvenida($id, $d, false));
        return ['id' => $id, 'mensaje' => $mensaje];
    }

    /** Confirmación al familiar de que quedó registrado (o de que su correo se actualizó) */
    private static function correoBienvenida(int $id, array $d, bool $cambioCorreo): array {
        $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
        $intro = $cambioCorreo
            ? 'Le confirmamos que este es ahora su correo de contacto en el sistema del asilo.'
            : 'Le confirmamos que quedó registrado(a) como familiar responsable en el sistema del asilo.';
        return Correo::enviar($d['email'], $cambioCorreo ? 'Su correo de contacto fue actualizado' : 'Registro como familiar responsable',
            "<p>Hola, {$e($d['nombre'])}:</p>
             <p>{$intro}</p>
             <table style=\"border-collapse:collapse;margin:8px 0\">
               <tr><td style=\"padding:3px 12px 3px 0;color:#5d6d67\">Nombre</td><td><strong>{$e($d['nombre'])} {$e($d['apellido'])}</strong></td></tr>
               <tr><td style=\"padding:3px 12px 3px 0;color:#5d6d67\">Parentesco</td><td>{$e($d['parentesco'])}</td></tr>
               <tr><td style=\"padding:3px 12px 3px 0;color:#5d6d67\">Teléfono</td><td>{$e($d['telefono'])}</td></tr>
             </table>
             <p>A este correo le avisaremos cuando su familiar sea remitido a un especialista y cuando se programe o cambie una cita.</p>
             <p>Si alguno de estos datos no es correcto, o usted no reconoce este registro, comuníquese con la administración del asilo.</p>");
    }

    public static function avisoCorreo(array $r): string {
        return $r['estado'] === 'Enviado' ? ' Se envió un correo de confirmación al familiar.' : '';
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
