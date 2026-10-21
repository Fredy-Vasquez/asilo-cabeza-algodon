<?php
// public/api.php
// Punto de entrada único de la API (Front Controller).
// Cada acción declara qué roles la pueden usar y si modifica datos (requiere token CSRF).

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/Core/Bitacora.php';
require_once __DIR__ . '/../src/Core/Auth.php';

foreach (glob(__DIR__ . '/../src/Controllers/*.php') as $archivo) {
    require_once $archivo;
}

const A = ROL_ADMIN, M = ROL_MEDICO, C = ROL_CONTADOR, E = ROL_ENFERMERO,
      F = ROL_FUNDACION, S = ROL_ESPECIALISTA, L = ROL_LABORATORIO, R = ROL_FARMACIA;

// acción => [Controlador, método, roles permitidos ([] = cualquiera con sesión), modifica datos]
$rutas = [
    'dashboard'               => ['DashboardController', 'resumen', [], false],

    // Internos y alojamiento
    'residentes'              => ['ResidenteController', 'listar', [], false],
    'residente_expediente'    => ['ResidenteController', 'expediente', [A, M, E, S], false],
    'residente_guardar'       => ['ResidenteController', 'guardar', [A], true],
    'residente_estado'        => ['ResidenteController', 'cambiarEstado', [A, M], true],

    'familiares'              => ['FamiliarController', 'listar', [A, C], false],
    'familiar_guardar'        => ['FamiliarController', 'guardar', [A], true],
    'familiar_eliminar'       => ['FamiliarController', 'eliminar', [A], true],

    'habitaciones'            => ['HabitacionController', 'listar', [], false],
    'habitaciones_disponibles'=> ['HabitacionController', 'disponibles', [A], false],
    'habitacion_guardar'      => ['HabitacionController', 'guardar', [A], true],
    'habitacion_eliminar'     => ['HabitacionController', 'eliminar', [A], true],

    // Ficha médica
    'medicamentos_catalogo'   => ['FichaController', 'catalogoMedicamentos', [A, M, S, R], false],
    'padecimiento_guardar'    => ['FichaController', 'guardarPadecimiento', [A, M], true],
    'padecimiento_eliminar'   => ['FichaController', 'eliminarPadecimiento', [A, M], true],
    'permanente_guardar'      => ['FichaController', 'guardarPermanente', [A, M], true],

    'evaluaciones'            => ['EvaluacionController', 'recientes', [A, M], false],
    'evaluacion_guardar'      => ['EvaluacionController', 'guardar', [A, M], true],

    // Atención médica: solicitudes (médico general → fundación) y visitas (especialista)
    'solicitudes_opciones'    => ['SolicitudController', 'opciones', [A, M, F], false],
    'solicitudes'             => ['SolicitudController', 'listar', [A, M, F, E, S], false],
    'solicitud_crear'         => ['SolicitudController', 'crear', [A, M], true],
    'solicitud_programar'     => ['SolicitudController', 'programar', [A, F], true],
    'solicitud_cancelar'      => ['SolicitudController', 'cancelar', [A, M, F], true],

    'visita_detalle'          => ['VisitaController', 'detalle', [A, M, S], false],
    'visita_iniciar'          => ['VisitaController', 'iniciar', [A, S], true],
    'visita_guardar'          => ['VisitaController', 'guardar', [A, S], true],
    'visita_examen_agregar'   => ['VisitaController', 'agregarExamen', [A, S], true],
    'visita_examen_quitar'    => ['VisitaController', 'quitarExamen', [A, S], true],
    'visita_receta_agregar'   => ['VisitaController', 'agregarReceta', [A, S], true],
    'visita_receta_quitar'    => ['VisitaController', 'quitarReceta', [A, S], true],
    'visita_finalizar'        => ['VisitaController', 'finalizar', [A, S], true],

    // Laboratorio: resultados de exámenes y catálogo
    'laboratorio'             => ['LaboratorioController', 'listar', [A, L], false],
    'laboratorio_resultado'   => ['LaboratorioController', 'registrarResultado', [A, L], true],
    'examenes_catalogo'       => ['LaboratorioController', 'catalogo', [A, L], false],
    'examen_guardar'          => ['LaboratorioController', 'guardarExamen', [A, L], true],

    // Farmacia: entrega de recetas e inventario
    'farmacia'                => ['FarmaciaController', 'listar', [A, R], false],
    'farmacia_entregar'       => ['FarmaciaController', 'entregar', [A, R], true],
    'inventario'              => ['FarmaciaController', 'inventario', [A, R], false],
    'medicamento_guardar'     => ['FarmaciaController', 'guardarMedicamento', [A, R], true],
    'inventario_ingreso'      => ['FarmaciaController', 'ingreso', [A, R], true],

    // Caja: cuentas de familiares, cuotas, fundación, donaciones y gastos
    'caja_resumen'            => ['CajaController', 'resumen', [A, C], false],
    'cuentas'                 => ['CajaController', 'cuentas', [A, C], false],
    'cuenta_estado'           => ['CajaController', 'estadoCuenta', [A, C], false],
    'pago_registrar'          => ['CajaController', 'registrarPago', [A, C], true],
    'cuotas_preview'          => ['CajaController', 'cuotasPreview', [A, C], false],
    'cuotas_generar'          => ['CajaController', 'generarCuotas', [A, C], true],
    'fundacion_cuenta'        => ['CajaController', 'deudaFundacion', [A, C, F], false],
    'fundacion_pagar'         => ['CajaController', 'pagarFundacion', [A, C], true],
    'donaciones'              => ['CajaController', 'donaciones', [A, C], false],
    'donacion_registrar'      => ['CajaController', 'registrarDonacion', [A, C], true],
    'gastos'                  => ['CajaController', 'gastos', [A, C], false],
    'gasto_registrar'         => ['CajaController', 'registrarGasto', [A, C], true],
    // Reportes (cada reporte valida además su propio rol)
    'reportes_opciones'       => ['ReporteController', 'opciones', [A, C, M, F], false],
    'reporte'                 => ['ReporteController', 'generar', [A, C, M, F], false],
    'cotizador_catalogo'      => ['CotizacionController', 'catalogo', [A, C], false],
    'cotizar'                 => ['CotizacionController', 'cotizar', [A, C], true],

    // Administración
    'usuarios'                => ['UsuarioController', 'listar', [A], false],
    'usuario_guardar'         => ['UsuarioController', 'guardar', [A], true],
    'configuracion'           => ['ConfiguracionController', 'obtener', [A], false],
    'configuracion_guardar'   => ['ConfiguracionController', 'guardar', [A], true],
    'correo_probar'           => ['ConfiguracionController', 'probarCorreo', [A], true],
    'correo_diagnostico'      => ['ConfiguracionController', 'diagnosticarCorreo', [A], true],
];

try {
    Auth::iniciarSesion();
    $accion = $_GET['action'] ?? '';
    $entrada = Http::input() + $_GET;

    if ($accion === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new HttpError('Método no permitido.', 405);
        Auth::verificarCsrf();
        $u = Auth::login((string)($entrada['email'] ?? ''), (string)($entrada['password'] ?? ''));
        Http::json(['status' => 'success', 'data' => $u]);
        exit;
    }
    if ($accion === 'logout') {
        Bitacora::registrar('LOGOUT', 'usuarios', 'Cierre de sesión');
        Auth::cerrar();
        Http::json(['status' => 'success']);
        exit;
    }
    if ($accion === 'bitacora') {
        Auth::exigir([A]);
        Http::json(['status' => 'success', 'data' => Bitacora::listar(300, $entrada['q'] ?? null)]);
        exit;
    }

    if (!isset($rutas[$accion])) throw new HttpError('Acción no válida o no especificada.', 404);
    [$clase, $metodo, $roles, $modifica] = $rutas[$accion];

    Auth::exigir($roles);
    if ($modifica) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new HttpError('Método no permitido.', 405);
        Auth::verificarCsrf();
    }

    $resultado = (new $clase())->$metodo($entrada);
    Http::json(['status' => 'success', 'data' => $resultado]);

} catch (HttpError $e) {
    Http::json(['status' => 'error', 'message' => $e->getMessage()], $e->status);
} catch (InvalidArgumentException $e) {
    Http::json(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (PDOException $e) {
    error_log('API PDO: ' . $e->getMessage());
    $codigo = (string)$e->getCode();
    if (in_array($codigo, ['42S22', '42S02'], true)) {
        // Columna o tabla inexistente: la base es de una versión anterior
        $msg = 'La base de datos está desactualizada. Importe database/schema.sql en phpMyAdmin.';
    } elseif (str_contains($e->getMessage(), 'foreign key')) {
        $msg = 'La operación viola una relación de la base de datos (registro en uso).';
    } else {
        $msg = 'Error en la base de datos. Revise el registro de errores de PHP.';
    }
    Http::json(['status' => 'error', 'message' => $msg], 500);
} catch (Throwable $e) {
    error_log('API: ' . $e->getMessage());
    Http::json(['status' => 'error', 'message' => $e->getMessage()], 500);
}
