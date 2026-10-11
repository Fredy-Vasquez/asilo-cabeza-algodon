<?php
// src/Services/ReglasClinicas.php
// Reglas de respaldo y clasificación de signos vitales.
// El IMC lo calcula el microservicio ms-imc; si no responde, se usa la misma
// fórmula aquí (tolerancia a fallos, RNF04) y se marca el origen como "local".
// Umbrales de referencia para adulto mayor; deben validarse con el personal médico.

class ReglasClinicas {
    public static function imcLocal(float $peso, float $altura): array {
        $imc = round($peso / ($altura * $altura), 2);
        if ($imc < 22.0)       { $cat = 'Bajo peso / riesgo de desnutrición'; $nivel = 'Atencion'; }
        elseif ($imc <= 27.0)  { $cat = 'Peso normal (rango geriátrico)';     $nivel = 'Normal'; }
        elseif ($imc <= 30.0)  { $cat = 'Sobrepeso';                          $nivel = 'Normal'; }
        else                   { $cat = 'Obesidad';                           $nivel = 'Atencion'; }
        return ['imc' => $imc, 'categoria' => $cat, 'nivel' => $nivel];
    }

    /**
     * Devuelve el nivel más alto entre IMC y signos vitales, y los motivos.
     * @return array{nivel:string, motivos:string[]}
     */
    public static function evaluarSignos(string $nivelImc, ?int $sis, ?int $dia, ?float $glucosa, ?int $pulso): array {
        $rango = ['Normal' => 0, 'Atencion' => 1, 'Urgente' => 2];
        $nivel = $nivelImc;
        $motivos = [];
        $subir = function (string $n, string $motivo) use (&$nivel, &$motivos, $rango) {
            $motivos[] = $motivo;
            if ($rango[$n] > $rango[$nivel]) $nivel = $n;
        };

        if ($nivelImc !== 'Normal') $motivos[] = 'IMC fuera del rango geriátrico';

        if ($sis !== null && $dia !== null) {
            if ($sis >= 180 || $dia >= 120)      $subir('Urgente', "Crisis hipertensiva ({$sis}/{$dia} mmHg)");
            elseif ($sis >= 140 || $dia >= 90)   $subir('Atencion', "Presión elevada ({$sis}/{$dia} mmHg)");
            elseif ($sis < 90 || $dia < 60)      $subir('Atencion', "Presión baja ({$sis}/{$dia} mmHg)");
        }
        if ($glucosa !== null) {
            if ($glucosa >= 250 || $glucosa < 54) $subir('Urgente', "Glucosa crítica ({$glucosa} mg/dL)");
            elseif ($glucosa >= 180 || $glucosa < 70) $subir('Atencion', "Glucosa fuera de rango ({$glucosa} mg/dL)");
        }
        if ($pulso !== null) {
            if ($pulso > 130 || $pulso < 40)       $subir('Urgente', "Ritmo cardíaco crítico ({$pulso} lpm)");
            elseif ($pulso > 100 || $pulso < 50)   $subir('Atencion', "Ritmo cardíaco fuera de rango ({$pulso} lpm)");
        }
        return ['nivel' => $nivel, 'motivos' => $motivos];
    }
}
