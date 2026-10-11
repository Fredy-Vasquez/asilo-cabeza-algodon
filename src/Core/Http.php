<?php
// src/Core/Http.php
// Utilidades para respuestas JSON y validación de datos de entrada.

class HttpError extends Exception {
    public int $status;
    public function __construct(string $mensaje, int $status = 400) {
        parent::__construct($mensaje);
        $this->status = $status;
    }
}

class Http {
    public static function json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /** Lee el cuerpo JSON o, en su defecto, $_POST */
    public static function input(): array {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : $_POST;
    }

    /** Texto limpio: sin etiquetas HTML y sin espacios sobrantes */
    public static function texto(array $d, string $campo, bool $requerido = false, int $max = 255, string $etiqueta = ''): ?string {
        $valor = isset($d[$campo]) ? trim(strip_tags((string)$d[$campo])) : '';
        $nombre = $etiqueta ?: $campo;
        if ($valor === '') {
            if ($requerido) throw new HttpError("El campo «{$nombre}» es obligatorio.", 422);
            return null;
        }
        if (mb_strlen($valor) > $max) throw new HttpError("El campo «{$nombre}» admite máximo {$max} caracteres.", 422);
        return $valor;
    }

    public static function numero(array $d, string $campo, bool $requerido = false, ?float $min = null, ?float $max = null, string $etiqueta = ''): ?float {
        $nombre = $etiqueta ?: $campo;
        if (!isset($d[$campo]) || $d[$campo] === '' || $d[$campo] === null) {
            if ($requerido) throw new HttpError("El campo «{$nombre}» es obligatorio.", 422);
            return null;
        }
        if (!is_numeric($d[$campo])) throw new HttpError("El campo «{$nombre}» debe ser numérico.", 422);
        $n = (float)$d[$campo];
        if ($min !== null && $n < $min) throw new HttpError("El campo «{$nombre}» debe ser mayor o igual a {$min}.", 422);
        if ($max !== null && $n > $max) throw new HttpError("El campo «{$nombre}» debe ser menor o igual a {$max}.", 422);
        return $n;
    }

    public static function entero(array $d, string $campo, bool $requerido = false, ?int $min = null, ?int $max = null, string $etiqueta = ''): ?int {
        $n = self::numero($d, $campo, $requerido, $min, $max, $etiqueta);
        return $n === null ? null : (int)$n;
    }

    public static function fecha(array $d, string $campo, bool $requerido = false, string $etiqueta = ''): ?string {
        $valor = self::texto($d, $campo, $requerido, 10, $etiqueta);
        if ($valor === null) return null;
        $f = DateTime::createFromFormat('Y-m-d', $valor);
        if (!$f || $f->format('Y-m-d') !== $valor) {
            throw new HttpError('La fecha «' . ($etiqueta ?: $campo) . '» no es válida.', 422);
        }
        return $valor;
    }

    public static function opcion(array $d, string $campo, array $permitidas, ?string $porDefecto = null, string $etiqueta = ''): string {
        $valor = isset($d[$campo]) ? (string)$d[$campo] : '';
        if ($valor === '' && $porDefecto !== null) return $porDefecto;
        if (!in_array($valor, $permitidas, true)) {
            throw new HttpError('Valor no permitido en «' . ($etiqueta ?: $campo) . '».', 422);
        }
        return $valor;
    }

    public static function ip(): string {
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }
}
