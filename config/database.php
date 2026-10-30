<?php
// config/database.php
// Patrón Singleton: una sola conexión PDO por petición HTTP.
// Al compartir la conexión, las transacciones abarcan a todos los modelos.

require_once __DIR__ . '/config.php';

class Database {
    private static ?Database $instance = null;
    private ?PDO $conn = null;

    private function __construct() {}

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        if ($this->conn === null) {
            try {
                $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                $this->conn = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                // NOW() y CURDATE() con la hora de Guatemala aunque el servidor esté en UTC (Azure)
                $this->conn->exec("SET time_zone = '-06:00'");
            } catch (PDOException $e) {
                throw new RuntimeException('No se pudo conectar a la base de datos. Verifique que MySQL esté encendido en XAMPP y que exista la base asilo_cabeza_algodon (importe database/schema.sql).');
            }
        }
        return $this->conn;
    }

    /** Atajo: Database::conn() */
    public static function conn(): PDO {
        return self::getInstance()->getConnection();
    }
}
