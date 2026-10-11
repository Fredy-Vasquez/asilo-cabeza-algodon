<?php
// src/Core/Bitacora.php
// Registro de auditoría (no repudio). Solo inserta: nunca modifica ni borra.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Http.php';

class Bitacora {
    /**
     * @param string     $accion  Ej.: REGISTRO_RESIDENTE, EVALUACION_IMC
     * @param string     $tabla   Tabla afectada
     * @param mixed      $detalles Texto o arreglo (se guarda como JSON)
     * @param int|null|false $idUsuario  false = usuario de la sesión actual
     */
    public static function registrar(string $accion, string $tabla, $detalles = null, $idUsuario = false): void {
        try {
            if ($idUsuario === false) {
                $idUsuario = $_SESSION['usuario']['id'] ?? null;
            }
            if (is_array($detalles)) {
                $detalles = json_encode($detalles, JSON_UNESCAPED_UNICODE);
            }
            $stmt = Database::conn()->prepare(
                'INSERT INTO bitacora_auditoria (id_usuario, accion, tabla_afectada, detalles, ip_origen)
                 VALUES (:u, :a, :t, :d, :ip)'
            );
            $stmt->execute([
                ':u'  => $idUsuario,
                ':a'  => $accion,
                ':t'  => $tabla,
                ':d'  => $detalles,
                ':ip' => Http::ip(),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca debe interrumpir la operación principal
            error_log('Bitácora: ' . $e->getMessage());
        }
    }

    public static function listar(int $limite = 200, ?string $buscar = null): array {
        $sql = 'SELECT b.id_log, b.fecha_hora, b.accion, b.tabla_afectada, b.detalles, b.ip_origen,
                       COALESCE(u.nombre, \'Sistema / anónimo\') AS usuario, r.nombre_rol AS rol
                FROM bitacora_auditoria b
                LEFT JOIN usuarios u ON u.id_usuario = b.id_usuario
                LEFT JOIN roles r ON r.id_rol = u.id_rol';
        $params = [];
        if ($buscar) {
            $sql .= ' WHERE b.accion LIKE :q OR b.tabla_afectada LIKE :q2 OR u.nombre LIKE :q3 OR b.detalles LIKE :q4';
            $like = '%' . $buscar . '%';
            $params = [':q' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        $sql .= ' ORDER BY b.id_log DESC LIMIT ' . max(1, min($limite, 1000));
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
