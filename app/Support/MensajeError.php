<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Traduce una excepción al texto que se le muestra al usuario.
 *
 * Regla del sistema: al usuario solo le llegan mensajes escritos para él. Las excepciones de
 * negocio (stock insuficiente, secuencia agotada, caja cerrada...) ya traen ese mensaje. Todo lo
 * demás (errores de base de datos, de red, de tipos, bugs) NO se muestra tal cual: revelaría SQL
 * y nombres de tablas, y al cajero no le dice qué hacer. En ese caso se registra el detalle en el
 * log con una referencia corta y se muestra un mensaje genérico con esa referencia, para que
 * soporte pueda encontrar el error exacto.
 *
 * Ojo: `catch (RuntimeException $e)` también atrapa QueryException (PDOException extiende
 * RuntimeException). Por eso los catch que muestran el mensaje deben pasar por aquí.
 */
final class MensajeError
{
    public const GENERICO = 'Ocurrió un error inesperado y no se guardó ningún cambio. Intente de nuevo; si se repite, contacte a soporte';

    /** Excepciones genéricas de PHP que los servicios lanzan con un mensaje para el usuario. */
    private const GENERICAS_DE_NEGOCIO = [
        \RuntimeException::class,
        \InvalidArgumentException::class,
        \DomainException::class,
        \LogicException::class,
    ];

    public static function para(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }

        if (self::esDeNegocio($e)) {
            return $e->getMessage();
        }

        $referencia = Str::upper(Str::random(6));

        Log::error("Error inesperado [ref {$referencia}]: ".$e->getMessage(), ['exception' => $e]);

        return self::GENERICO." (ref. {$referencia}).";
    }

    /** true si el mensaje de la excepción fue escrito para mostrárselo al usuario. */
    public static function esDeNegocio(Throwable $e): bool
    {
        if (blank($e->getMessage())) {
            return false;
        }

        if (str_starts_with($e::class, 'App\\Exceptions\\') || $e instanceof HttpExceptionInterface) {
            return true;
        }

        // Comparación EXACTA de clase: sus subclases (PDOException, QueryException,
        // ConnectionException, ProcessFailedException...) son errores de infraestructura.
        return in_array($e::class, self::GENERICAS_DE_NEGOCIO, true);
    }
}
