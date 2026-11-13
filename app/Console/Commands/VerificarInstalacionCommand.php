<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RespaldoService;
use Database\Seeders\FilamentAdminSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Revisión de una instalación (local o en la nube) antes de entregarla y después de actualizar.
 * Solo lee: no cambia nada. Sale con error si falta algo CRÍTICO; los avisos no lo hacen fallar.
 */
class VerificarInstalacionCommand extends Command
{
    protected $signature = 'erp:verificar-instalacion';

    protected $description = 'Revisa requisitos, seguridad y respaldos de esta instalación (solo lectura)';

    private int $criticos = 0;

    private int $avisos = 0;

    public function handle(RespaldoService $respaldos): int
    {
        $this->line('Requisitos');
        $this->critico('PHP 8.2 o superior ('.PHP_VERSION.')', PHP_VERSION_ID >= 80200);

        foreach (['pdo_pgsql', 'bcmath', 'openssl', 'mbstring', 'intl'] as $extension) {
            $this->critico("Extensión PHP {$extension}", extension_loaded($extension));
        }

        $this->aviso('Extensión PHP imagick (QR en el PDF y el ticket de e-CF)', extension_loaded('imagick'));
        $this->aviso('Extensión PHP zip (respaldo de logos, certificados y .env)', extension_loaded('zip'));

        [$ok, $detalle] = $this->postgres();
        $this->critico($detalle, $ok);

        $this->critico('Carpeta storage escribible', is_writable(storage_path()) && is_writable(storage_path('logs')));
        $this->critico('Fuentes locales (public/fonts/erp)', is_file(public_path('fonts/erp/fuentes.css')));
        $this->aviso('Assets compilados (npm run build)', is_file(public_path('build/manifest.json')));
        $this->aviso('Enlace public/storage (php artisan storage:link)', file_exists(public_path('storage')));

        $this->newLine();
        $this->line('Seguridad');

        if (app()->isProduction()) {
            $this->critico('APP_DEBUG desactivado', ! config('app.debug'));
        } else {
            $this->aviso('APP_ENV=production (ahora: '.app()->environment().')', false);
        }

        // En desarrollo la contraseña demo es lo esperado; en producción es una puerta abierta.
        [$ok, $detalle] = $this->superAdmins();
        app()->isProduction() ? $this->critico($detalle, $ok) : $this->aviso($detalle, $ok);

        $this->newLine();
        $this->line('Respaldos');

        [$ok, $detalle] = $this->pgDump();
        $this->aviso($detalle, $ok);

        $ultimo = $respaldos->ultimoRespaldo();
        $this->aviso(
            $ultimo === null ? 'Último respaldo: nunca (php artisan erp:respaldo)' : 'Último respaldo: '.$ultimo->diffForHumans(),
            $ultimo !== null && $ultimo->greaterThan(now()->subDays(2)),
        );
        $this->aviso('Segunda ubicación de respaldo (RESPALDO_COPIA_DIRECTORIO)', filled(config('instalacion.respaldo.copia_directorio')));

        $this->newLine();

        if ($this->criticos > 0) {
            $this->error("{$this->criticos} problema(s) crítico(s), {$this->avisos} aviso(s).");

            return self::FAILURE;
        }

        $this->info("Sin problemas críticos. {$this->avisos} aviso(s).");

        return self::SUCCESS;
    }

    /** true si la versión del servidor (formato server_version_num, p. ej. 180001) alcanza el mínimo. */
    public static function postgresCumple(int $versionNum, int $minimo): bool
    {
        return intdiv($versionNum, 10000) >= $minimo;
    }

    /** @return array{bool, string} */
    private function postgres(): array
    {
        $minimo = (int) config('instalacion.postgres_minimo', 18);

        try {
            $numero = (int) DB::selectOne('show server_version_num')->server_version_num;
        } catch (Throwable $e) {
            return [false, 'Conexión a PostgreSQL: '.$e->getMessage()];
        }

        return [
            self::postgresCumple($numero, $minimo),
            "PostgreSQL {$minimo} o superior (instalado: ".intdiv($numero, 10000).') — requerido por la búsqueda sin acentos',
        ];
    }

    /** @return array{bool, string} */
    private function superAdmins(): array
    {
        try {
            $conDemo = User::where('es_super_admin', true)->get()
                ->filter(fn (User $u) => Hash::check(FilamentAdminSeeder::PASSWORD_DEMO, $u->password))
                ->count();
        } catch (Throwable $e) {
            return [false, 'Super-admins: '.$e->getMessage()];
        }

        return [$conDemo === 0, $conDemo === 0
            ? 'Ningún super-admin con la contraseña por defecto'
            : "{$conDemo} super-admin(s) siguen con la contraseña por defecto: cámbiela ya"];
    }

    /** @return array{bool, string} */
    private function pgDump(): array
    {
        $binario = (string) config('instalacion.respaldo.pg_dump', 'pg_dump');

        try {
            $proceso = new Process([$binario, '--version'], timeout: 15);
            $proceso->run();
        } catch (Throwable) {
            return [false, "pg_dump no encontrado ({$binario}): sin él no hay respaldos"];
        }

        return $proceso->isSuccessful()
            ? [true, 'pg_dump disponible: '.trim($proceso->getOutput())]
            : [false, "pg_dump no encontrado ({$binario}): sin él no hay respaldos"];
    }

    private function critico(string $texto, bool $ok): void
    {
        if (! $ok) {
            $this->criticos++;
        }

        $this->line(($ok ? '  <info>OK</info>       ' : '  <error>CRÍTICO</error>  ').$texto);
    }

    private function aviso(string $texto, bool $ok): void
    {
        if (! $ok) {
            $this->avisos++;
        }

        $this->line(($ok ? '  <info>OK</info>       ' : '  <comment>AVISO</comment>    ').$texto);
    }
}
