<?php
// src/Controllers/EvaluacionController.php
// CU-01 Registrar evaluación médica (consume ms-imc, con respaldo local si no responde).

require_once __DIR__ . '/../Models/EvaluacionMedica.php';
require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Services/MicroservicioClient.php';
require_once __DIR__ . '/../Services/ReglasClinicas.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class EvaluacionController {
    private EvaluacionMedica $model;

    public function __construct() {
        $this->model = new EvaluacionMedica();
    }

    public function recientes(): array {
        return $this->model->recientes(50);
    }

    public function guardar(array $in): array {
        $idResidente = Http::entero($in, 'id_residente', true, 1, null, 'Residente');
        $peso   = Http::numero($in, 'peso_kg', true, 20, 250, 'Peso (kg)');
        $altura = Http::numero($in, 'altura_m', true, 1.0, 2.3, 'Estatura (m)');
        $sis    = Http::entero($in, 'presion_sistolica', false, 50, 260, 'Presión sistólica');
        $dia    = Http::entero($in, 'presion_diastolica', false, 30, 160, 'Presión diastólica');
        $glu    = Http::numero($in, 'glucosa', false, 20, 600, 'Glucosa');
        $pulso  = Http::entero($in, 'ritmo_cardiaco', false, 20, 220, 'Ritmo cardíaco');
        $diag   = Http::texto($in, 'diagnostico', false, 2000, 'Diagnóstico');

        if (($sis === null) !== ($dia === null)) {
            throw new HttpError('Ingrese ambos valores de presión arterial (sistólica y diastólica) o deje los dos vacíos.', 422);
        }
        if ($sis !== null && $dia >= $sis) {
            throw new HttpError('La presión diastólica debe ser menor que la sistólica.', 422);
        }

        $r = (new Residente())->obtener($idResidente);
        if (!$r) throw new HttpError('El residente no existe.', 404);
        if ($r['estado'] === 'inactivo') throw new HttpError('No se pueden registrar evaluaciones de un residente dado de baja.', 409);

        // 1. IMC desde el microservicio; si no responde, cálculo local (RNF04)
        $origen = 'microservicio';
        $aviso = null;
        try {
            $ms = (new MicroservicioClient())->calcularIMC($peso, $altura);
            $imc = ['imc' => (float)$ms['imc'], 'categoria' => $ms['categoria'], 'nivel' => $ms['nivel'] ?? (!empty($ms['alerta_nutricional']) ? 'Atencion' : 'Normal')];
        } catch (MicroservicioNoDisponible $e) {
            $imc = ReglasClinicas::imcLocal($peso, $altura);
            $origen = 'local';
            $aviso = 'El microservicio de IMC no respondió; el cálculo se hizo en el servidor principal con la misma fórmula.';
        }

        // 2. Nivel de alerta combinando IMC y signos vitales
        $alerta = ReglasClinicas::evaluarSignos($imc['nivel'], $sis, $dia, $glu, $pulso);

        $id = $this->model->registrar([
            'id_residente' => $idResidente, 'id_usuario' => Auth::id(),
            'peso_kg' => $peso, 'altura_m' => $altura, 'imc' => $imc['imc'], 'categoria_imc' => $imc['categoria'],
            'presion_sistolica' => $sis, 'presion_diastolica' => $dia, 'glucosa' => $glu, 'ritmo_cardiaco' => $pulso,
            'diagnostico' => $diag, 'nivel_alerta' => $alerta['nivel'], 'origen_calculo' => $origen,
        ]);
        Bitacora::registrar('EVALUACION_IMC', 'evaluaciones_medicas', [
            'id' => $id, 'residente' => $idResidente, 'imc' => $imc['imc'], 'nivel' => $alerta['nivel'], 'origen' => $origen,
        ]);

        return [
            'id' => $id,
            'imc' => $imc['imc'],
            'categoria' => $imc['categoria'],
            'nivel_alerta' => $alerta['nivel'],
            'motivos' => $alerta['motivos'],
            'origen_calculo' => $origen,
            'aviso' => $aviso,
            'residente' => "{$r['nombre']} {$r['apellido']}",
            'mensaje' => 'Evaluación registrada en el expediente.',
        ];
    }
}
