<?php
// src/Controllers/DashboardController.php

require_once __DIR__ . '/../Models/Residente.php';
require_once __DIR__ . '/../Models/Habitacion.php';
require_once __DIR__ . '/../Models/EvaluacionMedica.php';
require_once __DIR__ . '/../Services/MicroservicioClient.php';
require_once __DIR__ . '/../Models/SolicitudMedica.php';
require_once __DIR__ . '/../Models/Laboratorio.php';
require_once __DIR__ . '/../Models/Farmacia.php';
require_once __DIR__ . '/../Models/Caja.php';
require_once __DIR__ . '/../Core/Auth.php';

class DashboardController {
    public function resumen(): array {
        $habitaciones = (new Habitacion())->listar();
        $camas = 0; $ocupadas = 0;
        foreach ($habitaciones as $h) {
            if ($h['estado'] === 'mantenimiento') continue;
            $camas += $h['capacidad'];
            $ocupadas += $h['ocupantes'];
        }
        $eval = new EvaluacionMedica();
        $sol = new SolicitudMedica();
        $filtro = [];
        if (Auth::tieneRol([ROL_ENFERMERO])) $filtro['id_enfermero'] = Auth::id();
        if (Auth::tieneRol([ROL_ESPECIALISTA])) $filtro['id_medico_asignado'] = Auth::id();
        return [
            'residentes' => (new Residente())->contarPorEstado(),
            'camas' => ['total' => $camas, 'ocupadas' => $ocupadas],
            'habitaciones' => $habitaciones,
            'alertas' => $eval->alertasVigentes(),
            'evaluaciones_mes' => $eval->totalMes(),
            'solicitudes' => $sol->contarPorEstado(),
            'proximas_citas' => $sol->proximas(6, $filtro),
            'microservicios' => (new MicroservicioClient())->estado(),
            'laboratorio_pendientes' => (new Laboratorio())->contarPendientes(),
            'farmacia_pendientes' => (new Farmacia())->contarPendientes(),
            'stock_bajo' => (new Farmacia())->stockBajo(),
            'por_cobrar' => Auth::tieneRol([ROL_ADMIN, ROL_CONTADOR]) ? (new Caja())->porCobrar() : null,
        ];
    }
}
