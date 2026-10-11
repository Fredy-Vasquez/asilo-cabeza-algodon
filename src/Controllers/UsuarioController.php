<?php
// src/Controllers/UsuarioController.php
// Administración de cuentas del personal (solo Administrador).

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Catalogos.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class UsuarioController {
    private Usuario $model;

    public function __construct() {
        $this->model = new Usuario();
    }

    public function listar(): array {
        return ['usuarios' => $this->model->listar(), 'roles' => $this->model->roles(), 'especialidades' => (new Catalogos())->especialidades(false)];
    }

    public function guardar(array $in): array {
        $id = Http::entero($in, 'id_usuario');
        $d = [
            'nombre'   => Http::texto($in, 'nombre', true, 100, 'Nombre'),
            'email'    => mb_strtolower((string)Http::texto($in, 'email', true, 100, 'Correo')),
            'id_rol'   => Http::opcion(['v' => (string)($in['id_rol'] ?? '')], 'v', ['1', '2', '3', '4', '5', '6', '7', '8'], null, 'Rol'),
            'telefono' => Http::texto($in, 'telefono', false, 20, 'Teléfono'),
            'id_especialidad' => Http::entero($in, 'id_especialidad', false, 1, null, 'Especialidad'),
            'estado'   => Http::opcion($in, 'estado', ['activo', 'inactivo'], 'activo', 'Estado'),
            'password' => (string)($in['password'] ?? ''),
        ];
        $d['id_rol'] = (int)$d['id_rol'];
        if ($d['id_rol'] === ROL_ESPECIALISTA) {
            if (!$d['id_especialidad'] || !(new Catalogos())->especialidad($d['id_especialidad'])) {
                throw new HttpError('Indique la especialidad del médico especialista.', 422);
            }
        } else {
            $d['id_especialidad'] = null;
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new HttpError('El correo no tiene un formato válido.', 422);
        if ($this->model->existeEmail($d['email'], $id)) throw new HttpError('Ya existe un usuario con ese correo.', 409);
        if ((!$id || $d['password'] !== '') && !self::passwordSegura($d['password'])) {
            throw new HttpError('La contraseña debe tener al menos 8 caracteres, con letras y números.', 422);
        }
        if ($id && $id === Auth::id() && ($d['estado'] !== 'activo' || $d['id_rol'] !== ROL_ADMIN)) {
            throw new HttpError('No puede desactivar su propia cuenta ni quitarse el rol de administrador.', 409);
        }

        $registro = $d;
        unset($registro['password']);
        if ($id) {
            $this->model->actualizar($id, $d);
            Bitacora::registrar('ACTUALIZA_USUARIO', 'usuarios', ['id' => $id] + $registro + ['cambio_password' => $d['password'] !== '']);
            return ['id' => $id, 'mensaje' => 'Usuario actualizado.'];
        }
        $id = $this->model->crear($d);
        Bitacora::registrar('REGISTRO_USUARIO', 'usuarios', ['id' => $id] + $registro);
        return ['id' => $id, 'mensaje' => 'Usuario creado.'];
    }

    private static function passwordSegura(string $p): bool {
        return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
    }
}
