<?php
/**
 * Asilo Cabeza de Algodón — Plan de pruebas automatizado (pruebas funcionales y de seguridad sobre la API)
 *
 * Uso (desde la carpeta del proyecto, con Apache y MySQL encendidos y la base recién importada):
 *     C:\xampp\php\php.exe tests\ejecutar_pruebas.php http://localhost/asilo-cabeza-algodon/public
 *
 * El script crea registros de prueba: después de ejecutarlo vuelva a importar database/schema.sql.
 * Resultado: tabla en consola y archivo tests/resultados_pruebas.json (evidencia para SQA).
 */

$BASE = rtrim($argv[1] ?? 'http://localhost/asilo-cabeza-algodon/public', '/');
$CLAVE = 'Asilo2026!';
$resultados = [];
$tmp = sys_get_temp_dir();

/* ---------------- Cliente HTTP con sesión por usuario ---------------- */
class Cliente {
    public string $cookie;
    public string $csrf = '';
    public function __construct(public string $base, string $nombre) {
        $this->cookie = sys_get_temp_dir() . "/asilo_prueba_{$nombre}_" . getmypid() . '.txt';
        @unlink($this->cookie);
    }
    private function req(string $url, ?array $datos = null, bool $conCsrf = true): array {
        $ch = curl_init($url);
        $h = ['Accept: application/json'];
        if ($datos !== null) {
            $h[] = 'Content-Type: application/json';
            if ($conCsrf) $h[] = 'X-CSRF-Token: ' . $this->csrf;
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos));
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie,
            CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40]);
        $ini = microtime(true);
        $cuerpo = curl_exec($ch);
        $ms = (int)round((microtime(true) - $ini) * 1000);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http' => $codigo, 'ms' => $ms, 'raw' => $cuerpo, 'json' => json_decode((string)$cuerpo, true)];
    }
    public function abrirLogin(): array {
        $r = $this->req($this->base . '/login.php');
        if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', (string)$r['raw'], $m)) $this->csrf = $m[1];
        return $r;
    }
    public function login(string $email, string $clave): array {
        $this->abrirLogin();
        return $this->post('login', ['email' => $email, 'password' => $clave]);
    }
    public function get(string $accion, string $extra = ''): array { return $this->req("{$this->base}/api.php?action={$accion}{$extra}"); }
    public function post(string $accion, array $datos, bool $conCsrf = true): array { return $this->req("{$this->base}/api.php?action={$accion}", $datos, $conCsrf); }
    public function pagina(string $ruta): array { return $this->req("{$this->base}/{$ruta}"); }
}

/* ---------------- Registro de casos ---------------- */
function caso(string $id, string $rf, string $tipo, string $descripcion, string $esperado, callable $prueba): void {
    global $resultados;
    try {
        [$ok, $obtenido, $ms] = $prueba() + [null, null, 0];
    } catch (Throwable $e) {
        [$ok, $obtenido, $ms] = [false, 'Excepción: ' . $e->getMessage(), 0];
    }
    $resultados[] = compact('id', 'rf', 'tipo', 'descripcion', 'esperado', 'obtenido', 'ms') + ['estado' => $ok ? 'Aprobado' : 'Fallido'];
    printf("%-7s %-6s %-9s %s\n", $id, $rf, $ok ? 'APROBADO' : 'FALLIDO', $descripcion);
    if (!$ok) echo "        Obtenido: {$obtenido}\n";
}
$msg = fn(array $r) => $r['json']['message'] ?? ($r['json']['data']['mensaje'] ?? ('HTTP ' . $r['http']));
$exito = fn(array $r) => ($r['json']['status'] ?? '') === 'success';

echo "\nAsilo Cabeza de Algodón — Ejecución del plan de pruebas\nServidor: {$BASE}\nFecha: " . date('Y-m-d H:i:s') . "\n" . str_repeat('-', 78) . "\n";

$admin = new Cliente($BASE, 'admin');
$medico = new Cliente($BASE, 'medico');
$fund = new Cliente($BASE, 'fundacion');
$esp = new Cliente($BASE, 'internista');
$enf = new Cliente($BASE, 'enfermera');
$cont = new Cliente($BASE, 'contador');
$anon = new Cliente($BASE, 'anonimo');
$manana = date('Y-m-d', strtotime('+1 day'));

/* ===== Módulo de seguridad ===== */
caso('CP-01', 'RF-01', 'Funcional', 'Inicio de sesión con credenciales válidas (administrador)', 'Sesión iniciada con rol Administrador', function () use ($admin, $CLAVE, $exito) {
    $r = $admin->login('admin@asilo.com', $CLAVE);
    return [$exito($r) && $r['json']['data']['rol'] === 'Administrador', $r['json']['data']['rol'] ?? json_encode($r['json']), $r['ms']];
});
caso('CP-02', 'RF-01', 'Negativa', 'Inicio de sesión con contraseña incorrecta', 'Rechazo con HTTP 401 y mensaje genérico', function () use ($anon, $msg) {
    $r = $anon->login('admin@asilo.com', 'incorrecta');
    return [$r['http'] === 401 && str_contains($msg($r), 'incorrectos'), "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-03', 'RF-01', 'Seguridad', 'Intento de inyección SQL en el inicio de sesión', 'Acceso denegado (sentencias preparadas)', function () use ($anon) {
    $r = $anon->login("admin@asilo.com' OR '1'='1", "' OR '1'='1");
    return [$r['http'] === 401, "HTTP {$r['http']}", $r['ms']];
});
caso('CP-04', 'RF-02', 'Seguridad', 'Consultar la API sin haber iniciado sesión', 'HTTP 401 "Su sesión expiró"', function () use ($anon, $msg) {
    $r = $anon->get('residentes');
    return [$r['http'] === 401, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-05', 'RF-02', 'Seguridad', 'Petición que modifica datos sin token CSRF', 'HTTP 419, operación rechazada', function () use ($admin) {
    $r = $admin->post('familiar_guardar', ['nombre' => 'X'], false);
    return [$r['http'] === 419, "HTTP {$r['http']}", $r['ms']];
});
foreach ([[$medico, 'medico@asilo.com'], [$fund, 'fundacion@asilo.com'], [$esp, 'internista@asilo.com'], [$enf, 'enfermera@asilo.com'], [$cont, 'contador@asilo.com']] as [$c, $correo]) {
    $c->login($correo, $CLAVE);
}
caso('CP-06', 'RF-02', 'Seguridad', 'El contador intenta abrir un expediente médico', 'HTTP 403 (rol sin permiso)', function () use ($cont) {
    $r = $cont->get('residente_expediente', '&id_residente=1');
    return [$r['http'] === 403, "HTTP {$r['http']}", $r['ms']];
});
caso('CP-07', 'RF-02', 'Seguridad', 'El médico especialista intenta crear un usuario', 'HTTP 403 (rol sin permiso)', function () use ($esp) {
    $r = $esp->post('usuario_guardar', ['nombre' => 'X', 'email' => 'x@x.com', 'id_rol' => '1', 'password' => 'Clave1234']);
    return [$r['http'] === 403, "HTTP {$r['http']}", $r['ms']];
});
caso('CP-08', 'RF-02', 'Seguridad', 'La fundación intenta registrar un residente', 'HTTP 403 (rol sin permiso)', function () use ($fund) {
    $r = $fund->post('residente_guardar', ['nombre' => 'X']);
    return [$r['http'] === 403, "HTTP {$r['http']}", $r['ms']];
});
caso('CP-09', 'RF-03', 'Funcional', 'Crear usuario médico especialista con especialidad', 'Usuario creado', function () use ($admin, $exito, $msg) {
    $r = $admin->post('usuario_guardar', ['nombre' => 'Dr. Prueba SQA', 'email' => 'sqa.prueba@asilo.com', 'id_rol' => '6', 'id_especialidad' => '3', 'password' => 'Prueba2026']);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-10', 'RF-03', 'Negativa', 'Crear usuario con contraseña débil', 'Rechazo por política de contraseñas', function () use ($admin, $msg) {
    $r = $admin->post('usuario_guardar', ['nombre' => 'Débil', 'email' => 'debil@asilo.com', 'id_rol' => '4', 'password' => '123']);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-11', 'RF-03', 'Negativa', 'Crear usuario especialista sin especialidad', 'Rechazo: especialidad obligatoria', function () use ($admin, $msg) {
    $r = $admin->post('usuario_guardar', ['nombre' => 'Sin esp', 'email' => 'sinesp@asilo.com', 'id_rol' => '6', 'password' => 'Prueba2026']);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-12', 'RF-03', 'Seguridad', 'Contraseñas almacenadas cifradas (no en texto plano)', 'El nuevo usuario inicia sesión y la API nunca devuelve el hash', function () use ($BASE, $admin, $exito) {
    $c = new Cliente($BASE, 'sqa');
    $r = $c->login('sqa.prueba@asilo.com', 'Prueba2026');
    $u = $admin->get('usuarios');
    $sinHash = !str_contains((string)$u['raw'], 'password_hash') && !str_contains((string)$u['raw'], '$2y$');
    return [$exito($r) && $sinHash, ($exito($r) ? 'Inicia sesión' : 'No inicia') . ($sinHash ? '; hash no expuesto' : '; HASH EXPUESTO'), $r['ms']];
});

/* ===== Internos y alojamiento ===== */
$idFam = null;
caso('CP-13', 'RF-06', 'Funcional', 'Registrar familiar responsable', 'Familiar registrado', function () use ($admin, $exito, $msg, &$idFam) {
    $r = $admin->post('familiar_guardar', ['nombre' => 'Lucía', 'apellido' => 'Prueba SQA', 'telefono' => '5555-0101', 'parentesco' => 'Hija', 'documento_id' => '9999888877701', 'email' => 'lucia.sqa@correo.com']);
    $idFam = $r['json']['data']['id'] ?? null;
    return [$exito($r) && $idFam, $msg($r), $r['ms']];
});
caso('CP-14', 'RF-06', 'Negativa', 'Registrar familiar con DPI duplicado', 'HTTP 409, DPI ya existe', function () use ($admin, $msg) {
    $r = $admin->post('familiar_guardar', ['nombre' => 'Otro', 'apellido' => 'Dup', 'telefono' => '5555-0102', 'parentesco' => 'Hijo', 'documento_id' => '9999888877701']);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-15', 'RF-06', 'Seguridad', 'Código HTML/JavaScript en un nombre (XSS almacenado)', 'Las etiquetas se eliminan antes de guardar', function () use ($admin) {
    $r = $admin->post('familiar_guardar', ['nombre' => '<script>alert(1)</script>Mario', 'apellido' => 'XSS', 'telefono' => '5555-0103', 'parentesco' => 'Sobrino', 'documento_id' => '9999888877702']);
    $lista = $admin->get('familiares');
    $guardado = '';
    foreach ($lista['json']['data'] ?? [] as $f) if ($f['documento_id'] === '9999888877702') $guardado = $f['nombre'];
    return [$guardado !== '' && !str_contains($guardado, '<script'), "Guardado como: «{$guardado}»", $r['ms']];
});
$idRes = null;
caso('CP-16', 'RF-05', 'Funcional', 'Registrar residente con familiar, habitación, plan y motivo de ingreso', 'Residente registrado y habitación actualizada', function () use ($admin, $exito, $msg, &$idFam, &$idRes) {
    $r = $admin->post('residente_guardar', ['nombre' => 'Teresa', 'apellido' => 'Prueba SQA', 'fecha_nacimiento' => '1946-05-20', 'genero' => 'F', 'id_familiar' => $idFam, 'id_habitacion' => 5, 'id_plan' => 2, 'motivo_ingreso' => 'Requiere cuidados permanentes (caso de prueba)']);
    $idRes = $r['json']['data']['id'] ?? null;
    return [$exito($r) && $idRes, $msg($r), $r['ms']];
});
caso('CP-17', 'RF-07', 'Negativa', 'Asignar residente a habitación sin camas libres', 'HTTP 409, sin camas libres', function () use ($admin, $msg, &$idFam) {
    $r = $admin->post('residente_guardar', ['nombre' => 'Otra', 'apellido' => 'Persona', 'fecha_nacimiento' => '1946-05-20', 'genero' => 'F', 'id_familiar' => $idFam, 'id_habitacion' => 5, 'motivo_ingreso' => 'Prueba']);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-18', 'RF-07', 'Negativa', 'Asignar residente a habitación en mantenimiento', 'HTTP 409, habitación en mantenimiento', function () use ($admin, $msg, &$idFam) {
    $r = $admin->post('residente_guardar', ['nombre' => 'Otra', 'apellido' => 'Persona', 'fecha_nacimiento' => '1946-05-20', 'genero' => 'F', 'id_familiar' => $idFam, 'id_habitacion' => 8, 'motivo_ingreso' => 'Prueba']);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-19', 'RF-05', 'Negativa', 'Registrar residente con edad no válida (nacido en 2010)', 'HTTP 422, edad no válida', function () use ($admin, $msg, &$idFam) {
    $r = $admin->post('residente_guardar', ['nombre' => 'Niño', 'apellido' => 'X', 'fecha_nacimiento' => '2010-01-01', 'genero' => 'M', 'id_familiar' => $idFam, 'id_habitacion' => 3, 'motivo_ingreso' => 'Prueba']);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-20', 'RF-07', 'Negativa', 'Reducir la capacidad de una habitación por debajo de sus ocupantes', 'HTTP 409', function () use ($admin, $msg) {
    $r = $admin->post('habitacion_guardar', ['id_habitacion' => 2, 'numero_habitacion' => '102', 'piso' => 1, 'tipo' => 'Compartida', 'capacidad' => 1]);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-21', 'RF-06', 'Negativa', 'Eliminar un familiar que tiene residentes a cargo', 'HTTP 409, integridad referencial', function () use ($admin, $msg) {
    $r = $admin->post('familiar_eliminar', ['id_familiar' => 1]);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-22', 'RF-08', 'Funcional', 'Marcar residente como hospitalizado (conserva su cama)', 'Estado cambiado', function () use ($medico, $exito, $msg, &$idRes) {
    $r = $medico->post('residente_estado', ['id_residente' => $idRes, 'estado' => 'hospitalizado']);
    $r2 = $medico->post('residente_estado', ['id_residente' => $idRes, 'estado' => 'activo']);
    return [$exito($r) && $exito($r2), $msg($r), $r['ms']];
});

/* ===== Ficha médica ===== */
caso('CP-23', 'RF-09', 'Funcional', 'Registrar psicopatología en la ficha médica', 'Ficha actualizada', function () use ($medico, $exito, $msg, &$idRes) {
    $r = $medico->post('padecimiento_guardar', ['id_residente' => $idRes, 'tipo' => 'Psicopatología', 'descripcion' => 'Ansiedad generalizada (prueba)', 'estado' => 'Activo']);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-24', 'RF-09', 'Funcional', 'Registrar alergia en la ficha médica', 'Ficha actualizada', function () use ($medico, $exito, $msg, &$idRes) {
    $r = $medico->post('padecimiento_guardar', ['id_residente' => $idRes, 'tipo' => 'Alergia', 'descripcion' => 'Sulfas (prueba)', 'estado' => 'Activo']);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-25', 'RF-10', 'Funcional', 'Registrar medicamento permanente ("de cajón")', 'Medicamento guardado en la ficha', function () use ($medico, $exito, $msg, &$idRes) {
    $r = $medico->post('permanente_guardar', ['id_residente' => $idRes, 'id_medicamento' => 3, 'dosis' => '1 tableta', 'frecuencia' => 'Cada 8 horas si hay dolor']);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-26', 'RF-09', 'Negativa', 'Registrar padecimiento con fecha de diagnóstico futura', 'HTTP 422', function () use ($medico, $msg, &$idRes) {
    $r = $medico->post('padecimiento_guardar', ['id_residente' => $idRes, 'tipo' => 'Otro', 'descripcion' => 'X', 'fecha_diagnostico' => '2099-01-01']);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-27', 'RF-11', 'Funcional', 'Consultar expediente completo (datos, ficha, visitas, evaluaciones)', 'El expediente incluye padecimientos, medicamentos permanentes y visitas', function () use ($esp, $exito) {
    $r = $esp->get('residente_expediente', '&id_residente=1');
    $d = $r['json']['data'] ?? [];
    $ok = $exito($r) && isset($d['padecimientos'], $d['medicamentos_permanentes'], $d['visitas'], $d['evaluaciones']) && count($d['visitas']) >= 1;
    return [$ok, 'Padecimientos: ' . count($d['padecimientos'] ?? []) . ', visitas: ' . count($d['visitas'] ?? []), $r['ms']];
});
caso('CP-28', 'RF-12', 'Funcional', 'Evaluación médica con IMC y alerta por presión elevada', 'IMC calculado y nivel de alerta Urgente', function () use ($medico, $exito, &$idRes) {
    $r = $medico->post('evaluacion_guardar', ['id_residente' => $idRes, 'peso_kg' => '60', 'altura_m' => '1.55', 'presion_sistolica' => '185', 'presion_diastolica' => '100', 'ritmo_cardiaco' => '80']);
    $d = $r['json']['data'] ?? [];
    return [$exito($r) && abs(($d['imc'] ?? 0) - 24.97) < 0.02 && ($d['nivel_alerta'] ?? '') === 'Urgente', "IMC {$d['imc']} ({$d['origen_calculo']}), alerta {$d['nivel_alerta']}", $r['ms']];
});
caso('CP-29', 'RF-12', 'Negativa', 'Evaluación con presión diastólica mayor que la sistólica', 'HTTP 422', function () use ($medico, $msg, &$idRes) {
    $r = $medico->post('evaluacion_guardar', ['id_residente' => $idRes, 'peso_kg' => '60', 'altura_m' => '1.55', 'presion_sistolica' => '90', 'presion_diastolica' => '120']);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});

/* ===== Atención médica: solicitudes, fundación, visita ===== */
$idSol = null;
caso('CP-30', 'RF-13', 'Funcional', 'Médico general remite al residente a Medicina interna con enfermero', 'Solicitud creada en estado Pendiente', function () use ($medico, $exito, $msg, &$idRes, &$idSol) {
    $r = $medico->post('solicitud_crear', ['id_residente' => $idRes, 'id_especialidad' => 1, 'id_enfermero' => 4, 'prioridad' => 'Normal', 'motivo' => 'Dolor torácico ocasional (caso de prueba)']);
    $idSol = $r['json']['data']['id'] ?? null;
    return [$exito($r) && $idSol, $msg($r), $r['ms']];
});
caso('CP-31', 'RF-14', 'Funcional', 'Se intenta y registra el correo al familiar al crear la solicitud', 'Queda registro del correo (Enviado, Sin correo o detalle del error)', function () use ($admin, &$idSol) {
    $r = $admin->get('configuracion');
    $n = null;
    foreach ($r['json']['data']['correos'] ?? [] as $c) if ((int)$c['id_solicitud'] === (int)$idSol) { $n = $c; break; }
    return [$n !== null, $n ? "{$n['estado']} a {$n['destinatario']}" : 'Sin registro', $r['ms']];
});
caso('CP-32', 'RF-15', 'Funcional', 'La fundación ve la solicitud en su bandeja de pendientes', 'La solicitud aparece como Pendiente', function () use ($fund, &$idSol) {
    $r = $fund->get('solicitudes', '&estado=Pendiente');
    $ids = array_column($r['json']['data']['solicitudes'] ?? [], 'id_solicitud');
    return [in_array($idSol, $ids), count($ids) . ' pendientes', $r['ms']];
});
caso('CP-33', 'RF-15', 'Negativa', 'Programar con un médico de otra especialidad', 'HTTP 422', function () use ($fund, $msg, $manana, &$idSol) {
    $r = $fund->post('solicitud_programar', ['id_solicitud' => $idSol, 'id_medico_asignado' => 7, 'fecha_programada' => "{$manana}T11:00"]);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-34', 'RF-15', 'Negativa', 'Programar fuera del horario de atención (21:00)', 'HTTP 422', function () use ($fund, $msg, $manana, &$idSol) {
    $r = $fund->post('solicitud_programar', ['id_solicitud' => $idSol, 'id_medico_asignado' => 9, 'fecha_programada' => "{$manana}T21:00"]);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-35', 'RF-15', 'Funcional', 'La fundación asigna médico de la especialidad y horario', 'Cita programada', function () use ($fund, $exito, $msg, $manana, &$idSol) {
    $r = $fund->post('solicitud_programar', ['id_solicitud' => $idSol, 'id_medico_asignado' => 9, 'fecha_programada' => "{$manana}T11:00", 'id_enfermero' => 4]);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-36', 'RF-15', 'Negativa', 'Programar otra cita al mismo médico a la misma hora (choque)', 'HTTP 409', function () use ($fund, $msg, $manana) {
    $r = $fund->post('solicitud_programar', ['id_solicitud' => 3, 'id_medico_asignado' => 9, 'fecha_programada' => "{$manana}T11:15"]);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-37', 'RF-20', 'Funcional', 'La enfermera ve el acompañamiento asignado', 'La cita aparece en sus acompañamientos', function () use ($enf, &$idSol) {
    $r = $enf->get('solicitudes');
    $ids = array_column($r['json']['data']['solicitudes'] ?? [], 'id_solicitud');
    return [in_array($idSol, $ids), count($ids) . ' acompañamientos', $r['ms']];
});
caso('CP-38', 'RF-16', 'Seguridad', 'Otro especialista intenta abrir una cita que no es suya', 'HTTP 403', function () use ($BASE, $CLAVE, &$idSol) {
    $c = new Cliente($BASE, 'cardio');
    $c->login('cardiologo@asilo.com', $CLAVE);
    $r = $c->get('visita_detalle', "&id_solicitud={$idSol}");
    return [$r['http'] === 403, "HTTP {$r['http']}", $r['ms']];
});
caso('CP-39', 'RF-16', 'Funcional', 'El especialista asignado ve la solicitud y la ficha médica', 'Detalle con solicitud, padecimientos y alergias', function () use ($esp, $exito, &$idSol) {
    $r = $esp->get('visita_detalle', "&id_solicitud={$idSol}");
    $d = $r['json']['data'] ?? [];
    $alergia = in_array('Alergia', array_column($d['padecimientos'] ?? [], 'tipo'));
    return [$exito($r) && $alergia, 'Padecimientos: ' . count($d['padecimientos'] ?? []) . ($alergia ? ', incluye alergia' : ''), $r['ms']];
});
$idVis = null;
caso('CP-40', 'RF-17', 'Funcional', 'Iniciar la visita (la solicitud pasa a ser visita médica)', 'Visita creada en estado En curso', function () use ($esp, $exito, $msg, &$idSol, &$idVis) {
    $r = $esp->post('visita_iniciar', ['id_solicitud' => $idSol]);
    $idVis = $r['json']['data']['id_visita'] ?? null;
    return [$exito($r) && $idVis, $msg($r), $r['ms']];
});
caso('CP-41', 'RF-18', 'Funcional', 'Solicitar examen de laboratorio en la visita', 'Examen registrado para el laboratorio', function () use ($esp, $exito, $msg, &$idVis) {
    $r = $esp->post('visita_examen_agregar', ['id_visita' => $idVis, 'id_examen' => 1, 'indicaciones' => 'En ayunas']);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-42', 'RF-18', 'Negativa', 'Solicitar dos veces el mismo examen en la visita', 'HTTP 409', function () use ($esp, $msg, &$idVis) {
    $r = $esp->post('visita_examen_agregar', ['id_visita' => $idVis, 'id_examen' => 1]);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-43', 'RF-19', 'Funcional', 'Recetar medicamento con cantidad, indicaciones y duración', 'Receta registrada para farmacia', function () use ($esp, $exito, $msg, &$idVis) {
    $r = $esp->post('visita_receta_agregar', ['id_visita' => $idVis, 'id_medicamento' => 4, 'cantidad' => 2, 'indicaciones' => '1 cápsula en ayunas', 'duracion_dias' => 14]);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-44', 'RF-17', 'Negativa', 'Finalizar visita sin diagnóstico', 'HTTP 422, diagnóstico obligatorio', function () use ($esp, $msg, &$idVis) {
    $r = $esp->post('visita_finalizar', ['id_visita' => $idVis, 'diagnostico' => '']);
    return [$r['http'] === 422, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-45', 'RF-17', 'Funcional', 'Finalizar la visita con diagnóstico y observaciones', 'Visita finalizada y solicitud Atendida', function () use ($esp, $exito, $msg, &$idVis) {
    $r = $esp->post('visita_finalizar', ['id_visita' => $idVis, 'diagnostico' => 'Gastritis leve (prueba)', 'observaciones' => 'Dieta blanda']);
    return [$exito($r), $msg($r), $r['ms']];
});
caso('CP-46', 'RF-23', 'Funcional', 'La consulta se carga al familiar con el 30 % de descuento de la fundación', 'Cargo de Q 175.00 (Q 250.00 − 30 %)', function () use ($esp, &$idSol) {
    $r = $esp->get('visita_detalle', "&id_solicitud={$idSol}");
    $v = $r['json']['data']['visita'] ?? [];
    $neto = round($v['costo_consulta'] * (1 - $v['descuento_pct'] / 100), 2);
    return [($v['estado'] ?? '') === 'Finalizada' && $neto == 175.00, "Consulta {$v['costo_consulta']} con {$v['descuento_pct']} % = {$neto}", $r['ms']];
});
caso('CP-47', 'RF-17', 'Negativa', 'Modificar una visita ya finalizada', 'HTTP 409', function () use ($esp, $msg, &$idVis) {
    $r = $esp->post('visita_receta_agregar', ['id_visita' => $idVis, 'id_medicamento' => 3, 'cantidad' => 1, 'indicaciones' => 'x']);
    return [$r['http'] === 409, "HTTP {$r['http']}: " . $msg($r), $r['ms']];
});
caso('CP-48', 'RF-11', 'Funcional', 'La visita queda en el historial de la ficha médica', 'El expediente muestra la visita con exámenes y medicamentos', function () use ($admin, &$idRes, &$idVis) {
    $r = $admin->get('residente_expediente', "&id_residente={$idRes}");
    $v = null;
    foreach ($r['json']['data']['visitas'] ?? [] as $x) if ((int)$x['id_visita'] === (int)$idVis) $v = $x;
    return [$v && count($v['examenes']) === 1 && count($v['recetas']) === 1, $v ? "Visita con {$v['diagnostico']}" : 'No aparece', $r['ms']];
});

/* ===== Caja (valor agregado) y configuración ===== */
caso('CP-49', 'RF-28', 'Funcional', 'Cotizar estancia mensual (plan + habitación + cuidados + extras − descuento)', 'Total calculado correctamente', function () use ($cont, $exito) {
    $r = $cont->post('cotizar', ['id_plan' => 1, 'tipo_habitacion' => 'Privada', 'nivel_cuidados' => 'medio', 'extras' => ['dieta'], 'descuento_pct' => '10']);
    $d = $r['json']['data'] ?? [];
    $esperado = round((1500 + 150 + 100 + 100) * 0.9, 2);
    return [$exito($r) && abs($d['monto_total'] - $esperado) < 0.01, "Total {$d['monto_total']} (esperado {$esperado}, {$d['origen_calculo']})", $r['ms']];
});
caso('CP-50', 'RF-04', 'Funcional', 'Las acciones quedan registradas en la bitácora de auditoría', 'La bitácora contiene las acciones de la prueba', function () use ($admin) {
    $r = $admin->get('bitacora', '&q=VISITA_FINALIZADA');
    return [count($r['json']['data'] ?? []) >= 1, count($r['json']['data'] ?? []) . ' registros VISITA_FINALIZADA', $r['ms']];
});

/* ===== No funcionales ===== */
caso('CP-51', 'RNF-03', 'Rendimiento', 'Tiempo de respuesta del panel de inicio', 'Menos de 3 segundos', function () use ($admin) {
    $r = $admin->get('dashboard');
    return [$r['ms'] < 3000, "{$r['ms']} ms", $r['ms']];
});
caso('CP-52', 'RNF-02', 'Seguridad', 'La sesión se cierra correctamente', 'Después de cerrar sesión la API responde 401', function () use ($medico) {
    $medico->post('logout', []);
    $r = $medico->get('residentes');
    return [$r['http'] === 401, "HTTP {$r['http']}", $r['ms']];
});

/* ---------------- Resumen ---------------- */
$total = count($resultados);
$ok = count(array_filter($resultados, fn($x) => $x['estado'] === 'Aprobado'));
echo str_repeat('-', 78) . "\nTotal: {$total}   Aprobados: {$ok}   Fallidos: " . ($total - $ok) . '   Porcentaje: ' . round($ok / $total * 100, 1) . "%\n";
file_put_contents(__DIR__ . '/resultados_pruebas.json', json_encode([
    'servidor' => $BASE, 'fecha' => date('c'), 'total' => $total, 'aprobados' => $ok, 'casos' => $resultados,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Evidencia guardada en tests/resultados_pruebas.json\nRecuerde volver a importar database/schema.sql para limpiar los datos de prueba.\n";
foreach (glob($tmp . '/asilo_prueba_*_' . getmypid() . '.txt') as $f) @unlink($f);
