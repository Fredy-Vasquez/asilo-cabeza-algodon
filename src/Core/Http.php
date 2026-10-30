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

    /**
     * DPI de Guatemala (CUI): 13 dígitos. Acepta espacios o guiones al escribirlo ("2456 78123 0101")
     * y lo guarda solo con números. Verifica el departamento y municipio de emisión y el dígito
     * verificador, como lo hace el RENAP.
     */
    public static function dpi(?string $valor, string $etiqueta = 'DPI'): ?string {
        if ($valor === null || trim($valor) === '') return null;
        $cui = preg_replace('/[\s-]/', '', $valor);
        if (!preg_match('/^\d+$/', $cui)) throw new HttpError("El {$etiqueta} solo puede tener números (sin letras ni símbolos).", 422);
        if (strlen($cui) !== 13) throw new HttpError("El {$etiqueta} debe tener 13 dígitos; tiene " . strlen($cui) . '.', 422);
        $municipios = [17, 8, 16, 16, 13, 14, 19, 8, 24, 21, 9, 30, 32, 21, 8, 17, 14, 5, 11, 11, 7, 17];
        $depto = (int)substr($cui, 9, 2);
        $muni = (int)substr($cui, 11, 2);
        if ($depto < 1 || $depto > 22 || $muni < 1 || $muni > $municipios[$depto - 1]) {
            throw new HttpError("El {$etiqueta} no es válido: los últimos 4 dígitos no corresponden a un departamento y municipio de Guatemala.", 422);
        }
        $suma = 0;
        for ($i = 0; $i < 8; $i++) $suma += (int)$cui[$i] * ($i + 2);
        if ($suma % 11 !== (int)$cui[8]) throw new HttpError("El {$etiqueta} no es válido: revise que esté bien escrito (el dígito verificador no coincide).", 422);
        return $cui;
    }

    /** Teléfono de Guatemala: 8 dígitos (acepta 5512-3487, 5512 3487 o +502 5512 3487); se guarda como 5512-3487 */
    public static function telefono(?string $valor, bool $requerido, string $etiqueta = 'Teléfono'): ?string {
        if ($valor === null || trim($valor) === '') {
            if ($requerido) throw new HttpError("El campo «{$etiqueta}» es obligatorio.", 422);
            return null;
        }
        $num = preg_replace('/[\s\-()]/', '', $valor);
        $num = preg_replace('/^(\+?502)(\d{8})$/', '$2', $num);
        if (!preg_match('/^[2-7]\d{7}$/', $num)) {
            throw new HttpError("El {$etiqueta} debe tener 8 dígitos (por ejemplo 5512-3487), sin letras.", 422);
        }
        return substr($num, 0, 4) . '-' . substr($num, 4);
    }

    /** Nombres y apellidos de personas: solo letras (con tildes y ñ), espacios, punto, apóstrofo y guion */
    public static function nombrePersona(array $d, string $campo, bool $requerido, int $max, string $etiqueta): ?string {
        $v = self::texto($d, $campo, $requerido, $max, $etiqueta);
        if ($v !== null && !preg_match("/^[\p{L}][\p{L}\s.'\-]*$/u", $v)) {
            throw new HttpError("El campo «{$etiqueta}» solo puede tener letras (sin números ni símbolos).", 422);
        }
        return $v;
    }

    /**
     * IP del usuario. En Azure todas las peticiones llegan a través del proxy HTTPS (Caddy),
     * así que REMOTE_ADDR es la IP interna de Docker; la IP real viene en X-Forwarded-For.
     * Solo se confía en ese encabezado cuando quien conecta es una dirección interna (el proxy).
     */
    public static function ip(): string {
        $remota = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $reenviada = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($reenviada !== '' && filter_var($remota, FILTER_VALIDATE_IP)
            && !filter_var($remota, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $partes = array_map('trim', explode(',', $reenviada));
            $real = end($partes);   // la última la agrega nuestro propio proxy
            if (filter_var($real, FILTER_VALIDATE_IP)) return $real;
        }
        return $remota;
    }

    /** Dirección de la pantalla de ingreso (sirve igual en XAMPP y en Azure) */
    public static function urlSistema(): string {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return ($https ? 'https' : 'http') . '://' . $host . $dir . '/login.php';
    }
}
