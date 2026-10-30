<?php
// src/Controllers/UsuarioController.php
// Administración de cuentas del personal (solo Administrador).

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Catalogos.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';
require_once __DIR__ . '/../Services/Correo.php';
require_once __DIR__ . '/CuentaController.php';

class UsuarioController {
    private Usuario $model;

    public function __construct() {
        $this->model = new Usuario();
    }

    public function listar(): array {
        return [
            'usuarios' => $this->model->listar(),
            'roles' => $this->model->roles(),
            'especialidades' => (new Catalogos())->especialidades(false),
            'solicitudes_clave' => $this->model->solicitudesClavePendientes(),
            'correo_listo' => Correo::estado()['listo'],
        ];
    }

    public function guardar(array $in): array {
        $id = Http::entero($in, 'id_usuario');
        $d = [
            'nombre'   => Http::texto($in, 'nombre', true, 100, 'Nombre'),
            'email'    => mb_strtolower((string)Http::texto($in, 'email', true, 100, 'Correo')),
            'id_rol'   => Http::opcion(['v' => (string)($in['id_rol'] ?? '')], 'v', ['1', '2', '3', '4', '5', '6', '7', '8'], null, 'Rol'),
            'telefono' => Http::telefono(isset($in['telefono']) ? (string)$in['telefono'] : null, false, 'teléfono'),
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
        if ((!$id || $d['password'] !== '') && !CuentaController::segura($d['password'])) {
            throw new HttpError('La contraseña debe tener al menos 8 caracteres, con letras y números.', 422);
        }
        if ($id && $id === Auth::id() && ($d['estado'] !== 'activo' || $d['id_rol'] !== ROL_ADMIN)) {
            throw new HttpError('No puede desactivar su propia cuenta ni quitarse el rol de administrador.', 409);
        }

        $registro = $d;
        unset($registro['password']);
        if ($id) {
            $antes = $this->model->obtener($id);
            if (!$antes) throw new HttpError('El usuario no existe.', 404);
            // Si cambia lo que define su acceso, sus sesiones abiertas se cierran de inmediato
            $cambiaAcceso = $antes['estado'] !== $d['estado'] || (int)$antes['id_rol'] !== $d['id_rol']
                || (int)$antes['id_especialidad'] !== (int)$d['id_especialidad'] || $antes['email'] !== $d['email'];
            $propio = $id === Auth::id();
            // ¿Y si se desactiva o se le cambia el rol a alguien con citas por atender? Se pide reasignarlas primero
            $dejaRol = $antes['estado'] === 'activo' && ($d['estado'] !== 'activo' || (int)$antes['id_rol'] !== $d['id_rol']
                || ((int)$antes['id_rol'] === ROL_ESPECIALISTA && (int)$antes['id_especialidad'] !== (int)$d['id_especialidad']));
            if ($dejaRol) {
                $c = $this->model->citasPendientes($id);
                if ($c['como_medico'] || $c['como_enfermero']) {
                    $partes = [];
                    if ($c['como_medico']) $partes[] = "{$c['como_medico']} cita(s) programada(s) como especialista";
                    if ($c['como_enfermero']) $partes[] = "{$c['como_enfermero']} acompañamiento(s) como enfermero(a)";
                    throw new HttpError("{$antes['nombre']} tiene " . implode(' y ', $partes) . ' sin atender. Pida a la fundación que las reprograme con otra persona (Solicitudes > Programar) y luego vuelva a intentarlo.', 409);
                }
            }
            $this->model->actualizar($id, $d, $cambiaAcceso && !$propio);
            $mensaje = 'Usuario actualizado.';
            if ($d['password'] !== '') {
                // Contraseña escrita por el admin para otra persona = temporal (deberá cambiarla al ingresar)
                $version = $this->model->cambiarClave($id, $d['password'], !$propio);
                if ($propio) Auth::renovarSesion($version);
                $mensaje = $propio ? 'Datos y contraseña actualizados.' : 'Usuario actualizado. La contraseña nueva es temporal: se le pedirá cambiarla al ingresar.';
            } elseif ($cambiaAcceso && !$propio) {
                $mensaje = 'Usuario actualizado. Si tenía una sesión abierta, se cerró para aplicar los cambios.';
            }
            Bitacora::registrar('ACTUALIZA_USUARIO', 'usuarios', ['id' => $id] + $registro + ['cambio_password' => $d['password'] !== '', 'cierra_sesiones' => $cambiaAcceso && !$propio]);
            return ['id' => $id, 'mensaje' => $mensaje];
        }
        $id = $this->model->crear($d);
        Bitacora::registrar('REGISTRO_USUARIO', 'usuarios', ['id' => $id] + $registro);
        return ['id' => $id, 'mensaje' => 'Usuario creado. Al ingresar por primera vez, el sistema le pedirá cambiar la contraseña.'];
    }

    /** Genera una contraseña temporal (p. ej. "Algodon-4827"), la muestra una sola vez y opcionalmente la envía por correo */
    public function restablecer(array $in): array {
        $id = Http::entero($in, 'id_usuario', true);
        if ($id === Auth::id()) throw new HttpError('Para cambiar su propia contraseña use "Cambiar mi contraseña" en el menú.', 409);
        $u = $this->model->obtener($id);
        if (!$u) throw new HttpError('El usuario no existe.', 404);
        if ($u['estado'] !== 'activo') throw new HttpError('El usuario está desactivado. Actívelo primero para que pueda ingresar.', 409);

        $palabras = ['Algodon', 'Ceiba', 'Quetzal', 'Volcan', 'Maiz', 'Cacao', 'Jade', 'Pino'];
        $clave = $palabras[random_int(0, count($palabras) - 1)] . '-' . random_int(1000, 9999);
        $this->model->cambiarClave($id, $clave, true);

        $correo = null;
        if (!empty($in['enviar_correo'])) {
            $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
            $correo = Correo::enviar($u['email'], 'Su contraseña temporal del sistema del asilo',
                "<p>Hola, {$e($u['nombre'])}:</p>
                 <p>El administrador restableció su acceso. Su contraseña temporal es:</p>
                 <p style=\"font-size:22px;font-weight:bold;letter-spacing:1px;background:#fff2da;padding:10px 14px;border-radius:8px;display:inline-block\">{$e($clave)}</p>
                 <p>Ingrese con su correo (<strong>{$e($u['email'])}</strong>) y esta contraseña. El sistema le pedirá crear una nueva antes de continuar.</p>
                 <p>Si usted no pidió este cambio, avise a la administración.</p>" . Correo::botonIngreso());
        }
        Bitacora::registrar('RESTABLECE_CLAVE', 'usuarios', ['id' => $id, 'email' => $u['email'], 'correo' => $correo['estado'] ?? 'no enviado']);
        return [
            'clave_temporal' => $clave,
            'correo' => $correo,
            'mensaje' => 'Contraseña temporal generada. Se cerraron las sesiones abiertas del usuario y se desbloqueó su cuenta.',
        ];
    }

    public function descartarSolicitudClave(array $in): array {
        $id = Http::entero($in, 'id_solicitud_clave', true);
        if (!$this->model->descartarSolicitudClave($id, Auth::id())) throw new HttpError('La solicitud ya fue atendida o no existe.', 409);
        Bitacora::registrar('DESCARTA_SOLICITUD_CLAVE', 'solicitudes_clave', ['id' => $id]);
        return ['mensaje' => 'Solicitud descartada.'];
    }
}
