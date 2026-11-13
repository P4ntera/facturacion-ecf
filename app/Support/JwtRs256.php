<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Verificación mínima de un JWT firmado con RS256, usando solo la extensión openssl de PHP.
 *
 * Existe para que la validación de licencias no dependa de un paquete externo: una instalación
 * local debe poder verificar su licencia con lo que trae `composer install`, sin pasos extra.
 * Solo verifica (no firma) y solo acepta RS256: cualquier otro `alg` del header se rechaza, para
 * que un token no pueda elegir un algoritmo más débil (p. ej. `none` o HS256 con la clave pública).
 */
final class JwtRs256
{
    /** Tolerancia de reloj, en segundos, al evaluar `exp` y `nbf`. */
    private const TOLERANCIA = 60;

    /**
     * Devuelve los claims del token si la firma es válida y no está vencido.
     *
     * @throws RuntimeException si el token está mal formado, la firma no cuadra o ya expiró.
     */
    public static function decode(string $token, string $clavePublicaPem): object
    {
        $partes = explode('.', $token);

        if (count($partes) !== 3) {
            throw new RuntimeException('Token mal formado.');
        }

        [$headerB64, $payloadB64, $firmaB64] = $partes;

        $header = json_decode(self::base64Url($headerB64));
        $claims = json_decode(self::base64Url($payloadB64));

        if (! is_object($header) || ! is_object($claims)) {
            throw new RuntimeException('Token mal formado.');
        }

        if (($header->alg ?? null) !== 'RS256') {
            throw new RuntimeException('Algoritmo de firma no admitido.');
        }

        $clave = openssl_pkey_get_public($clavePublicaPem);

        if ($clave === false) {
            throw new RuntimeException('Clave pública inválida.');
        }

        $resultado = openssl_verify("{$headerB64}.{$payloadB64}", self::base64Url($firmaB64), $clave, OPENSSL_ALGO_SHA256);

        if ($resultado !== 1) {
            throw new RuntimeException('Firma inválida.');
        }

        $ahora = time();

        if (isset($claims->nbf) && is_numeric($claims->nbf) && (int) $claims->nbf > $ahora + self::TOLERANCIA) {
            throw new RuntimeException('Token todavía no vigente.');
        }

        if (isset($claims->exp) && is_numeric($claims->exp) && (int) $claims->exp < $ahora - self::TOLERANCIA) {
            throw new RuntimeException('Token expirado.');
        }

        return $claims;
    }

    private static function base64Url(string $valor): string
    {
        $decodificado = base64_decode(strtr($valor, '-_', '+/'), true);

        if ($decodificado === false) {
            throw new RuntimeException('Token mal formado.');
        }

        return $decodificado;
    }
}
