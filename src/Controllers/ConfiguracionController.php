<?php
// src/Controllers/ConfiguracionController.php
// Parámetros generales (descuento de la fundación, datos del asilo) y verificación del correo.

require_once __DIR__ . '/../Services/Correo.php';
require_once __DIR__ . '/../Core/Bitacora.php';
require_once __DIR__ . '/../Core/Auth.php';

class ConfiguracionController {
    public function obtener(): array {
        $filas = Database::conn()->query('SELECT clave, valor, descripcion FROM configuracion ORDER BY clave')->fetchAll();
        $cfg = [];
        foreach ($filas as $f) $cfg[$f['clave']] = $f['valor'];
        return [
            'config' => $cfg,
            'correo' => Correo::estado() + ['usuario' => defined('CORREO_USUARIO') && Correo::configurado() ? CORREO_USUARIO : null],
            'correos' => Correo::listar(100),
            'mi_correo' => Auth::usuario()['email'] ?? '',
        ];
    }

    public function guardar(array $in): array {
        $valores = [
            'descuento_fundacion_pct' => (string)Http::numero($in, 'descuento_fundacion_pct', true, 0, 90, 'Descuento de la fundación (%)'),
            'nombre_asilo'            => Http::texto($in, 'nombre_asilo', true, 120, 'Nombre del asilo'),
            'telefono_asilo'          => Http::texto($in, 'telefono_asilo', false, 20, 'Teléfono') ?? '',
        ];
        $stmt = Database::conn()->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?');
        foreach ($valores as $k => $v) $stmt->execute([$v, $k]);
        Bitacora::registrar('CONFIGURACION', 'configuracion', $valores);
        return ['mensaje' => 'Configuración guardada. El nuevo descuento aplica a las visitas que se inicien desde ahora.'];
    }

    public function diagnosticarCorreo(): array {
        $r = Correo::diagnostico();
        Bitacora::registrar('CORREO_DIAGNOSTICO', 'notificaciones_correo', array_map(fn($x) => $x['prueba'] . ': ' . ($x['ok'] ? 'OK' : 'FALLA'), $r));
        return $r;
    }

    public function probarCorreo(array $in): array {
        $para = Http::texto($in, 'para', true, 100, 'Correo de prueba');
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new HttpError('Escriba un correo válido.', 422);
        $r = Correo::enviar($para, 'Prueba de correo del sistema',
            '<p>Si está leyendo esto, el sistema del asilo ya puede enviar correos a los familiares.</p><p>Enviado el ' . date('d/m/Y \a \l\a\s H:i') . '.</p>');
        Bitacora::registrar('CORREO_PRUEBA', 'notificaciones_correo', ['para' => $para, 'estado' => $r['estado']]);
        if ($r['estado'] !== 'Enviado') throw new HttpError('No se pudo enviar: ' . $r['detalle'], 502);
        return ['mensaje' => "Correo de prueba enviado a {$para}. Revise la bandeja de entrada (y la carpeta de spam)."];
    }
}
