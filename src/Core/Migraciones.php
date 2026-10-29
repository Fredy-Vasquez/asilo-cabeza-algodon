<?php
// src/Core/Migraciones.php
// Actualiza automáticamente una base de datos creada con una versión anterior de schema.sql,
// sin borrar datos. Se ejecuta una vez por sesión (Auth::iniciarSesion) y es idempotente:
// cada cambio verifica primero si ya existe.

require_once __DIR__ . '/../../config/database.php';

class Migraciones {
    /** Súbase este número cada vez que se agregue un paso */
    public const VERSION = 3;

    public static function aplicar(PDO $db): void {
        // v2: seguridad de cuentas (contraseña temporal, bloqueo por intentos, cierre de sesión remoto)
        $columnas = [
            'debe_cambiar_clave' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'intentos_fallidos'  => 'INT NOT NULL DEFAULT 0',
            'bloqueado_hasta'    => 'DATETIME NULL',
            'sesion_version'     => 'INT NOT NULL DEFAULT 0',
        ];
        foreach ($columnas as $col => $def) {
            if (!self::existeColumna($db, 'usuarios', $col)) {
                $db->exec("ALTER TABLE usuarios ADD COLUMN {$col} {$def}");
            }
        }
        if (!self::existeTabla($db, 'solicitudes_clave')) {
            $db->exec(self::TABLA_SOLICITUDES_CLAVE);
        }
        // v3: anulación de pagos, donaciones y gastos (nunca se borran) y corrección de resultados de laboratorio
        foreach (['pagos_familiares', 'pagos_fundacion', 'donaciones', 'gastos'] as $tabla) {
            foreach (self::COLUMNAS_ANULACION as $col => $def) {
                if (!self::existeColumna($db, $tabla, $col)) $db->exec("ALTER TABLE {$tabla} ADD COLUMN {$col} {$def}");
            }
        }
        foreach (self::COLUMNAS_CORRECCION as $col => $def) {
            if (!self::existeColumna($db, 'examenes_visita', $col)) $db->exec("ALTER TABLE examenes_visita ADD COLUMN {$col} {$def}");
        }
    }

    public const COLUMNAS_ANULACION = [
        'anulado'          => 'TINYINT(1) NOT NULL DEFAULT 0',
        'motivo_anulacion' => 'VARCHAR(255) NULL',
        'anulado_por'      => 'INT NULL',
        'anulado_en'       => 'DATETIME NULL',
    ];
    public const COLUMNAS_CORRECCION = [
        'resultado_original' => 'TEXT NULL',
        'motivo_correccion'  => 'VARCHAR(255) NULL',
        'corregido_por'      => 'INT NULL',
        'corregido_en'       => 'DATETIME NULL',
    ];

    public const TABLA_SOLICITUDES_CLAVE = "CREATE TABLE IF NOT EXISTS solicitudes_clave (
        id_solicitud_clave INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        estado ENUM('Pendiente', 'Atendida', 'Descartada') NOT NULL DEFAULT 'Pendiente',
        ip VARCHAR(45) NULL,
        creada_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        atendida_en DATETIME NULL,
        atendida_por INT NULL,
        CONSTRAINT fk_solclave_usuario FOREIGN KEY (id_usuario)
            REFERENCES usuarios(id_usuario) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_solclave_admin FOREIGN KEY (atendida_por)
            REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB";

    private static function existeColumna(PDO $db, string $tabla, string $col): bool {
        $s = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$tabla, $col]);
        return (int)$s->fetchColumn() > 0;
    }

    private static function existeTabla(PDO $db, string $tabla): bool {
        $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$tabla]);
        return (int)$s->fetchColumn() > 0;
    }
}
