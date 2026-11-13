<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\LicenciaSoloLecturaException;
use Illuminate\Support\Facades\DB;

/**
 * Candado de "solo lectura" a nivel de base de datos para la request en curso.
 *
 * VerificarLicencia lo activa cuando la licencia de la empresa activa no es válida. Mientras está
 * activo, cualquier INSERT/UPDATE/DELETE/TRUNCATE sobre una tabla de negocio lanza
 * LicenciaSoloLecturaException ANTES de ejecutarse (y revierte la transacción que lo contenga).
 *
 * Es la garantía real del modo solo lectura: no depende de adivinar por el nombre del método de
 * Livewire si una acción escribe o no (cobrar, facturar, anular, entregar ticket...). Las tablas
 * de infraestructura (sesión, caché, colas, licencia) quedan fuera, o no se podría ni navegar.
 *
 * Solo afecta a la request que lo activa: los jobs en cola (p. ej. el envío de e-CF pendientes)
 * siguen funcionando.
 */
class LicenciaSoloLectura
{
    /** Tablas que se pueden escribir aun en modo solo lectura. */
    public const TABLAS_PERMITIDAS = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'users',
        'notifications',
        'activity_log',
        'exports',
        'imports',
        'failed_import_rows',
        'empresa_license_states',
    ];

    private bool $activo = false;

    /** @var array<int, true> Conexiones que ya tienen el gancho puesto (por spl_object_id). */
    private static array $conexionesConGancho = [];

    public function activar(): void
    {
        $this->activo = true;

        $conexion = DB::connection();
        $id = spl_object_id($conexion);

        if (! isset(self::$conexionesConGancho[$id])) {
            // El gancho resuelve el candado del contenedor en cada consulta (no captura $this):
            // en un proceso de larga vida siempre consulta el candado de la request en curso.
            $conexion->beforeExecuting(static function (string $sql): void {
                app(self::class)->verificar($sql);
            });

            self::$conexionesConGancho = [$id => true];
        }
    }

    public function desactivar(): void
    {
        $this->activo = false;
    }

    public function activo(): bool
    {
        return $this->activo;
    }

    /** @throws LicenciaSoloLecturaException */
    public function verificar(string $sql): void
    {
        if (! $this->activo) {
            return;
        }

        $tabla = self::tablaEscrita($sql);

        if ($tabla !== null && ! in_array($tabla, self::TABLAS_PERMITIDAS, true)) {
            throw new LicenciaSoloLecturaException($tabla);
        }
    }

    /** Tabla que modifica la sentencia, o null si es de solo lectura. */
    public static function tablaEscrita(string $sql): ?string
    {
        $patron = '/^\s*(?:insert\s+into|update|delete\s+from|truncate(?:\s+table)?)\s+(?:only\s+)?(?:"?\w+"?\.)?"?(\w+)"?/i';

        return preg_match($patron, $sql, $m) === 1 ? strtolower($m[1]) : null;
    }
}
