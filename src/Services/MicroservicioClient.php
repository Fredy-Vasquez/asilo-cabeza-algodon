<?php
// src/Services/MicroservicioClient.php
// Patrón API Gateway / Adapter: único punto de contacto con los microservicios Flask.

require_once __DIR__ . '/../../config/config.php';

class MicroservicioNoDisponible extends Exception {}

class MicroservicioClient {
    private string $urlImc;
    private string $urlCotizador;
    private int $timeout;

    public function __construct() {
        $this->urlImc = rtrim(MS_IMC_URL, '/') . '/api/v1/imc';
        $this->urlCotizador = rtrim(MS_COTIZADOR_URL, '/') . '/api/v1/cotizar';
        $this->timeout = MS_TIMEOUT;
    }

    /** POST /api/v1/imc  →  {imc, categoria, alerta_nutricional, nivel} */
    public function calcularIMC(float $pesoKg, float $alturaM): array {
        return $this->post($this->urlImc, ['peso_kg' => $pesoKg, 'altura_m' => $alturaM]);
    }

    /** POST /api/v1/cotizar  →  desglose y monto_total */
    public function cotizarEstancia(float $costoBase, float $costoHabitacion, string $nivelCuidados, array $serviciosExtra = [], float $descuentoPct = 0): array {
        return $this->post($this->urlCotizador, [
            'costo_base'       => $costoBase,
            'costo_habitacion' => $costoHabitacion,
            'nivel_cuidados'   => $nivelCuidados,
            'servicios_extra'  => $serviciosExtra,
            'descuento_pct'    => $descuentoPct,
        ]);
    }

    /** Estado de los microservicios para el panel */
    public function estado(): array {
        return [
            'ms_imc'       => $this->vivo(rtrim(MS_IMC_URL, '/') . '/health'),
            'ms_cotizador' => $this->vivo(rtrim(MS_COTIZADOR_URL, '/') . '/health'),
        ];
    }

    private function vivo(string $url): bool {
        if (!function_exists('curl_init')) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1]);
        curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $ok;
    }

    private function post(string $url, array $payload): array {
        if (!function_exists('curl_init')) {
            throw new MicroservicioNoDisponible('La extensión cURL de PHP no está habilitada.');
        }
        $json = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);
        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $error) {
            throw new MicroservicioNoDisponible('Microservicio sin respuesta: ' . $error);
        }
        $datos = json_decode($respuesta, true);
        if ($codigo !== 200 || !is_array($datos)) {
            throw new InvalidArgumentException($datos['error'] ?? "El microservicio respondió con código {$codigo}.");
        }
        return $datos;
    }
}
