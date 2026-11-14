<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Consultas a servicios de terceros (RNC en la DGII, cédula en la JCE): caché + límite de uso.
 *
 * - Caché: el nombre de un contribuyente casi no cambia y cada consulta sale a internet (hasta
 *   10 s si el servicio está lento). Un resultado encontrado se guarda un día; "no encontrado"
 *   solo un minuto, porque también cubre el caso de que el servicio estuviera caído. Son datos
 *   públicos, iguales para todas las empresas: la clave no lleva empresa.
 * - Límite: máximo de consultas NUEVAS por minuto por usuario (o por IP si no hay sesión), para
 *   que nadie use el sistema como buscador masivo ni nos bloqueen el proveedor. Lo que sale de
 *   caché no cuenta.
 */
final class ConsultaExterna
{
    public const POR_MINUTO = 20;

    private const TTL_ENCONTRADO = 86400;

    private const TTL_NO_ENCONTRADO = 60;

    /**
     * @param  Closure(): (array<string, mixed>|null)  $consultar
     * @return array<string, mixed>|null
     *
     * @throws TooManyRequestsHttpException
     */
    public static function conCacheYLimite(string $servicio, string $documento, Closure $consultar): ?array
    {
        $clave = "consulta_externa:{$servicio}:{$documento}";

        // Se guarda envuelto para distinguir "no está en caché" de "en caché: no encontrado".
        $guardado = Cache::get($clave);

        if (is_array($guardado) && array_key_exists('dato', $guardado)) {
            return $guardado['dato'];
        }

        $quien = 'consulta_externa:'.(auth()->id() !== null ? 'u'.auth()->id() : 'ip'.request()->ip());

        if (RateLimiter::tooManyAttempts($quien, self::POR_MINUTO)) {
            throw new TooManyRequestsHttpException(
                RateLimiter::availableIn($quien),
                'Demasiadas consultas seguidas. Espere un minuto e intente de nuevo.'
            );
        }

        RateLimiter::hit($quien, 60);

        $dato = $consultar();

        Cache::put($clave, ['dato' => $dato], $dato !== null ? self::TTL_ENCONTRADO : self::TTL_NO_ENCONTRADO);

        return $dato;
    }
}
