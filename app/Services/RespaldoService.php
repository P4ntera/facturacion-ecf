<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Respaldo de una instalación: volcado de PostgreSQL (pg_dump, formato custom) más un zip con
 * los archivos que no viven en la base (logos, fotos, certificados .p12 y el .env con la APP_KEY,
 * sin la cual los secretos cifrados no se pueden leer).
 *
 * Es global a la instalación (todas las empresas), no por empresa: se restaura la base completa.
 */
class RespaldoService
{
    private const PREFIJO = 'erp-';

    /**
     * @return array{base: string, archivos: ?string, copia: ?string, avisos: list<string>, eliminados: int}
     */
    public function crear(): array
    {
        $directorio = $this->directorio();
        File::ensureDirectoryExists($directorio);

        $nombre = self::PREFIJO.now()->format('Ymd-His');
        $avisos = [];

        $base = $this->volcarBase($directorio.DIRECTORY_SEPARATOR.$nombre.'.dump');
        $archivos = $this->comprimirArchivos($directorio.DIRECTORY_SEPARATOR.$nombre.'-archivos.zip', $avisos);
        $copia = $this->copiar(array_filter([$base, $archivos]), $avisos);

        $eliminados = $this->depurar($directorio);

        if ($copia !== null) {
            $eliminados += $this->depurar($copia);
        }

        return ['base' => $base, 'archivos' => $archivos, 'copia' => $copia, 'avisos' => $avisos, 'eliminados' => $eliminados];
    }

    public function directorio(): string
    {
        return (string) (config('instalacion.respaldo.directorio') ?: storage_path('app/respaldos'));
    }

    /** Fecha del volcado más reciente, o null si nunca se ha respaldado. */
    public function ultimoRespaldo(): ?Carbon
    {
        $volcados = glob($this->directorio().DIRECTORY_SEPARATOR.self::PREFIJO.'*.dump') ?: [];

        if ($volcados === []) {
            return null;
        }

        return Carbon::createFromTimestamp(max(array_map('filemtime', $volcados)));
    }

    private function volcarBase(string $destino): string
    {
        $conexion = config('database.connections.'.config('database.default'));

        if (($conexion['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('El respaldo automático solo soporta PostgreSQL.');
        }

        $proceso = new Process(
            [
                (string) config('instalacion.respaldo.pg_dump', 'pg_dump'),
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--file='.$destino,
                '--host='.$conexion['host'],
                '--port='.$conexion['port'],
                '--username='.$conexion['username'],
                (string) $conexion['database'],
            ],
            env: ['PGPASSWORD' => (string) $conexion['password']],
            timeout: 900,
        );

        try {
            $proceso->run();
        } catch (\Throwable $e) {
            @unlink($destino);

            throw new RuntimeException('No se pudo ejecutar pg_dump: '.$e->getMessage(), previous: $e);
        }

        if (! $proceso->isSuccessful() || ! is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);

            throw new RuntimeException(
                'pg_dump falló: '.(trim($proceso->getErrorOutput()) ?: 'sin salida. ¿Está instalado y en el PATH? (RESPALDO_PG_DUMP)')
            );
        }

        return $destino;
    }

    /** @param list<string> $avisos */
    private function comprimirArchivos(string $destino, array &$avisos): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            $avisos[] = 'Sin la extensión zip de PHP no se respaldan los archivos (logos, certificados, .env).';

            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $avisos[] = 'No se pudo crear el zip de archivos.';

            return null;
        }

        foreach (['private', 'public'] as $carpeta) {
            $raiz = storage_path('app'.DIRECTORY_SEPARATOR.$carpeta);

            if (! is_dir($raiz)) {
                continue;
            }

            foreach (File::allFiles($raiz, hidden: false) as $archivo) {
                // Temporales regenerables (exportaciones, subidas a medio hacer): no son datos.
                if (preg_match('#^(filament_exports|filament_imports|livewire-tmp)[\\\\/]#', $archivo->getRelativePathname()) === 1) {
                    continue;
                }

                $zip->addFile($archivo->getPathname(), 'storage/app/'.$carpeta.'/'.str_replace('\\', '/', $archivo->getRelativePathname()));
            }
        }

        if (is_file(base_path('.env'))) {
            $zip->addFile(base_path('.env'), '.env');
        }

        // Un zip sin entradas no llega a escribirse en disco: se deja constancia de que no falta nada.
        $zip->addFromString('LEEME.txt', 'Respaldo de archivos del ERP generado el '.now()->toDateTimeString().".\n");
        $zip->close();

        return is_file($destino) ? $destino : null;
    }

    /**
     * @param  array<string>  $archivos
     * @param  list<string>  $avisos
     */
    private function copiar(array $archivos, array &$avisos): ?string
    {
        $copia = config('instalacion.respaldo.copia_directorio');

        if (blank($copia)) {
            $avisos[] = 'El respaldo quedó solo en este equipo. Configure RESPALDO_COPIA_DIRECTORIO (otro disco, USB o carpeta sincronizada).';

            return null;
        }

        if (! is_dir($copia) || ! is_writable($copia)) {
            $avisos[] = "No se pudo copiar a «{$copia}»: la carpeta no existe o no se puede escribir (¿disco desconectado?).";

            return null;
        }

        foreach ($archivos as $archivo) {
            if (! @copy($archivo, $copia.DIRECTORY_SEPARATOR.basename($archivo))) {
                $avisos[] = 'No se pudo copiar '.basename($archivo)." a «{$copia}».";

                return null;
            }
        }

        return (string) $copia;
    }

    /** Borra los respaldos propios más viejos que la retención. Nunca toca otros archivos. */
    public function depurar(string $directorio): int
    {
        $dias = max(1, (int) config('instalacion.respaldo.retencion_dias', 14));
        $limite = now()->subDays($dias)->getTimestamp();
        $eliminados = 0;

        foreach (['*.dump', '*-archivos.zip'] as $patron) {
            foreach (glob($directorio.DIRECTORY_SEPARATOR.self::PREFIJO.$patron) ?: [] as $archivo) {
                if (filemtime($archivo) < $limite && @unlink($archivo)) {
                    $eliminados++;
                }
            }
        }

        return $eliminados;
    }
}
