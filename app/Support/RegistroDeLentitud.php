<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deja escrito en `storage/logs/lento-AAAA-MM-DD.log` cada consulta y cada petición que tarde más
 * de lo razonable, para enterarse de un problema de rendimiento antes de que el cliente se queje.
 * Umbrales en `config/instalacion.php` (`lento.*`). Resumen legible: `php artisan erp:lentitud`.
 *
 * De cada consulta se guarda el SQL con sus `?`, NUNCA los valores: los valores pueden ser
 * nombres, cédulas o montos de clientes y un log no es lugar para eso.
 */
final class RegistroDeLentitud
{
    public static function registrar(): void
    {
        $umbralConsulta = (int) config('instalacion.lento.consulta_ms', 500);
        $umbralPeticion = (int) config('instalacion.lento.peticion_ms', 2000);

        if ($umbralConsulta > 0) {
            DB::listen(function (QueryExecuted $consulta) use ($umbralConsulta): void {
                if ($consulta->time < $umbralConsulta) {
                    return;
                }

                self::escribir('consulta lenta', [
                    'ms' => (int) round($consulta->time),
                    'sql' => mb_substr(preg_replace('/\s+/', ' ', $consulta->sql) ?? '', 0, 1500),
                ]);
            });
        }

        if ($umbralPeticion > 0 && ! app()->runningInConsole()) {
            app()->terminating(function () use ($umbralPeticion): void {
                $ms = (int) round((microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true))) * 1000);

                if ($ms >= $umbralPeticion) {
                    self::escribir('petición lenta', ['ms' => $ms]);
                }
            });
        }
    }

    /** @param array<string, mixed> $datos */
    private static function escribir(string $tipo, array $datos): void
    {
        try {
            Log::channel('lento')->warning($tipo, $datos + self::contexto());
        } catch (Throwable) {
            // Registrar la lentitud nunca debe tumbar la petición que se está midiendo.
        }
    }

    /** @return array<string, mixed> */
    private static function contexto(): array
    {
        if (app()->runningInConsole()) {
            return ['origen' => 'consola: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2))];
        }

        $request = request();

        return [
            'origen' => $request->method().' /'.ltrim($request->path(), '/'),
            // En Livewire todas las acciones van a /livewire/update: el componente dice cuál pantalla.
            'componente' => self::componenteLivewire($request->input('components.0.snapshot')),
            'usuario' => auth()->id(),
        ];
    }

    private static function componenteLivewire(mixed $snapshot): ?string
    {
        if (! is_string($snapshot)) {
            return null;
        }

        return json_decode($snapshot, true)['memo']['name'] ?? null;
    }
}
