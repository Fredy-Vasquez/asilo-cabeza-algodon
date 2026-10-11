<?php
// src/Controllers/SolicitudController.php
// CU: El médico general remite al interno a una especialidad y asigna enfermero acompañante.
//     La fundación asigna el médico especialista y el horario.
//     En ambos momentos se avisa por correo al familiar responsable.

require_once __DIR__ . '/../Models/SolicitudMedica.php';
require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Catalogos.php';
require_once __DIR__ . '/../Models/EvaluacionMedica.php';
require_once __DIR__ . '/../Services/Correo.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class SolicitudController {
    private SolicitudMedica $model;

    public function __construct() {
        $this->model = new SolicitudMedica();
    }

    /** Listas para los formularios */
    public function opciones(): array {
        $u = new Usuario();
        return [
            'especialidades' => (new Catalogos())->especialidades(),
            'enfermeros' => $u->porRol(ROL_ENFERMERO),
            'especialistas' => $u->porRol(ROL_ESPECIALISTA),
        ];
    }

    /** Cada rol ve lo que le corresponde */
    public function listar(array $in): array {
        $filtro = ['estado' => in_array($in['estado'] ?? '', SolicitudMedica::ESTADOS, true) ? $in['estado'] : null];
        if (!empty($in['id_residente'])) $filtro['id_residente'] = (int)$in['id_residente'];
        if (Auth::tieneRol([ROL_ENFERMERO])) $filtro['id_enfermero'] = Auth::id();
        if (Auth::tieneRol([ROL_ESPECIALISTA])) $filtro['id_medico_asignado'] = Auth::id();
        return [
            'solicitudes' => $this->model->listar($filtro),
            'conteo' => $this->model->contarPorEstado(),
        ];
    }

    public function crear(array $in): array {
        $d = [
            'id_residente'      => Http::entero($in, 'id_residente', true, 1, null, 'Residente'),
            'id_especialidad'   => Http::entero($in, 'id_especialidad', true, 1, null, 'Especialidad'),
            'id_enfermero'      => Http::entero($in, 'id_enfermero', false, 1, null, 'Enfermero acompañante'),
            'motivo'            => Http::texto($in, 'motivo', true, 2000, 'Motivo de la remisión'),
            'prioridad'         => Http::opcion($in, 'prioridad', SolicitudMedica::PRIORIDADES, 'Normal', 'Prioridad'),
            'id_medico_general' => Auth::id(),
        ];
        $r = (new Residente())->obtener($d['id_residente']);
        if (!$r) throw new HttpError('El residente no existe.', 404);
        if ($r['estado'] === 'inactivo') throw new HttpError('El residente está dado de baja.', 409);
        $esp = (new Catalogos())->especialidad($d['id_especialidad']);
        if (!$esp || !$esp['activa']) throw new HttpError('Seleccione una especialidad válida.', 422);
        if ($d['id_enfermero'] && !$this->esDelRol($d['id_enfermero'], ROL_ENFERMERO)) {
            throw new HttpError('El acompañante seleccionado no es un enfermero activo.', 422);
        }

        $id = $this->model->crear($d);
        Bitacora::registrar('SOLICITUD_CREADA', 'solicitudes_medicas', ['id' => $id, 'residente' => "{$r['nombre']} {$r['apellido']}", 'especialidad' => $esp['nombre'], 'prioridad' => $d['prioridad']]);

        $correo = $this->avisarFamiliar($id, 'creada');
        return ['id' => $id, 'mensaje' => 'Solicitud enviada a la fundación.', 'correo' => $correo];
    }

    public function programar(array $in): array {
        $id = Http::entero($in, 'id_solicitud', true);
        $idMedico = Http::entero($in, 'id_medico_asignado', true, 1, null, 'Médico especialista');
        $fecha = Http::texto($in, 'fecha_programada', true, 16, 'Fecha y hora');
        $idEnfermero = Http::entero($in, 'id_enfermero', false, 1, null, 'Enfermero acompañante');
        $notas = Http::texto($in, 'notas_fundacion', false, 1000, 'Notas');

        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $fecha) ?: DateTime::createFromFormat('Y-m-d H:i', $fecha);
        if (!$dt) throw new HttpError('La fecha y hora no son válidas.', 422);
        if ($dt < new DateTime('-5 minutes')) throw new HttpError('La cita no puede programarse en el pasado.', 422);
        $hora = (int)$dt->format('G');
        if ($hora < 7 || $hora >= 18) throw new HttpError('El horario de atención de la fundación es de 7:00 a 18:00.', 422);
        $fechaSql = $dt->format('Y-m-d H:i:00');

        $db = Database::conn();
        $db->beginTransaction();
        try {
            $s = $this->model->obtener($id, true);
            if (!$s) throw new HttpError('La solicitud no existe.', 404);
            if (!in_array($s['estado'], ['Pendiente', 'Programada'], true)) {
                throw new HttpError("La solicitud ya está {$s['estado']}; no se puede programar.", 409);
            }
            $medico = (new Usuario())->porRol(ROL_ESPECIALISTA, (int)$s['id_especialidad']);
            if (!in_array($idMedico, array_map('intval', array_column($medico, 'id_usuario')), true)) {
                throw new HttpError("El médico seleccionado no pertenece a {$s['especialidad']}.", 422);
            }
            if ($idEnfermero && !$this->esDelRol($idEnfermero, ROL_ENFERMERO)) {
                throw new HttpError('El acompañante seleccionado no es un enfermero activo.', 422);
            }
            $choques = $this->model->choquesHorario($idMedico, $fechaSql, $id);
            if ($choques) {
                $c = $choques[0];
                throw new HttpError('El médico ya tiene cita a las ' . substr($c['fecha_programada'], 11, 5) . " con {$c['nombre']} {$c['apellido']}. Elija otro horario.", 409);
            }
            $reprogramada = $s['estado'] === 'Programada';
            $this->model->programar($id, $idMedico, $fechaSql, $idEnfermero ?: ($s['id_enfermero'] ? (int)$s['id_enfermero'] : null), $notas);
            Bitacora::registrar($reprogramada ? 'SOLICITUD_REPROGRAMADA' : 'SOLICITUD_PROGRAMADA', 'solicitudes_medicas', ['id' => $id, 'medico' => $idMedico, 'fecha' => $fechaSql]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $correo = $this->avisarFamiliar($id, $reprogramada ? 'reprogramada' : 'programada');
        return ['mensaje' => $reprogramada ? 'Cita reprogramada.' : 'Cita programada.', 'correo' => $correo];
    }

    public function cancelar(array $in): array {
        $id = Http::entero($in, 'id_solicitud', true);
        $motivo = Http::texto($in, 'motivo', true, 500, 'Motivo de la cancelación');
        $s = $this->model->obtener($id);
        if (!$s) throw new HttpError('La solicitud no existe.', 404);
        if (!in_array($s['estado'], ['Pendiente', 'Programada'], true)) {
            throw new HttpError("No se puede cancelar una solicitud {$s['estado']}.", 409);
        }
        if ($s['id_visita']) throw new HttpError('La visita ya fue iniciada por el médico; no se puede cancelar.', 409);
        $this->model->cambiarEstado($id, 'Cancelada', trim(($s['notas_fundacion'] ? $s['notas_fundacion'] . "\n" : '') . 'Cancelada: ' . $motivo));
        Bitacora::registrar('SOLICITUD_CANCELADA', 'solicitudes_medicas', ['id' => $id, 'motivo' => $motivo]);
        return ['mensaje' => 'Solicitud cancelada.'];
    }

    private function esDelRol(int $idUsuario, int $rol): bool {
        return in_array($idUsuario, array_map('intval', array_column((new Usuario())->porRol($rol), 'id_usuario')), true);
    }

    /** Correo al familiar: estado del paciente, especialidad y médico al que fue referido */
    private function avisarFamiliar(int $idSolicitud, string $momento): array {
        $s = $this->model->obtener($idSolicitud);
        $r = (new Residente())->obtener((int)$s['id_residente']);
        $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
        $nombre = $e("{$r['nombre']} {$r['apellido']}");

        $evals = (new EvaluacionMedica())->porResidente((int)$r['id_residente']);
        $estadoTxt = ['activo' => 'estable, bajo el cuidado del asilo', 'hospitalizado' => 'hospitalizado', 'inactivo' => 'dado de baja'][$r['estado']] ?? $r['estado'];
        $ultima = $evals[0] ?? null;
        $estadoHtml = "<p><strong>Estado actual:</strong> {$e($estadoTxt)}";
        if ($ultima) {
            $alerta = ['Normal' => 'sin alertas', 'Atencion' => 'requiere atención', 'Urgente' => 'requiere atención urgente'][$ultima['nivel_alerta']];
            $estadoHtml .= '. Última evaluación del ' . date('d/m/Y', strtotime($ultima['fecha_evaluacion'])) . ": {$e($alerta)}";
            if ($ultima['presion_sistolica']) $estadoHtml .= ", presión {$ultima['presion_sistolica']}/{$ultima['presion_diastolica']} mmHg";
        }
        $estadoHtml .= '.</p>';

        if ($momento === 'creada') {
            $asunto = "{$r['nombre']} fue remitido(a) a {$s['especialidad']}";
            $html = "<p>Estimado(a) {$e($r['familiar_nombre'] . ' ' . $r['familiar_apellido'])}:</p>
                <p>Le informamos que <strong>{$nombre}</strong> fue evaluado(a) por {$e($s['medico_general'])}, médico general del asilo, y fue <strong>remitido(a) a {$e($s['especialidad'])}</strong>" .
                ($s['prioridad'] === 'Urgente' ? ' con <strong>prioridad urgente</strong>' : '') . ".</p>
                <p><strong>Motivo:</strong> {$e($s['motivo'])}</p>{$estadoHtml}" .
                ($s['enfermero'] ? "<p>Le acompañará el enfermero(a) {$e($s['enfermero'])}.</p>" : '') .
                '<p>La fundación asignará al médico especialista y el horario. Le enviaremos otro correo con esos datos.</p>';
        } else {
            $fecha = date('d/m/Y', strtotime($s['fecha_programada']));
            $hora = date('H:i', strtotime($s['fecha_programada']));
            $asunto = ($momento === 'reprogramada' ? 'Cambio de cita' : 'Cita programada') . " para {$r['nombre']}: {$s['especialidad']}";
            $html = "<p>Estimado(a) {$e($r['familiar_nombre'] . ' ' . $r['familiar_apellido'])}:</p>
                <p>La fundación " . ($momento === 'reprogramada' ? '<strong>cambió</strong> la cita' : 'programó la cita') . " de <strong>{$nombre}</strong>:</p>
                <ul><li><strong>Médico:</strong> {$e($s['medico_asignado'])} ({$e($s['especialidad'])})</li>
                <li><strong>Fecha:</strong> {$fecha} a las {$hora} horas</li>" .
                ($s['enfermero'] ? "<li><strong>Acompañante:</strong> {$e($s['enfermero'])}</li>" : '') . "</ul>
                <p><strong>Motivo de la remisión:</strong> {$e($s['motivo'])}</p>{$estadoHtml}";
        }
        $res = Correo::enviar($r['familiar_email'] ?? null, $asunto, $html, $idSolicitud);
        $res['destinatario'] = $r['familiar_email'] ?? null;
        return $res;
    }
}
