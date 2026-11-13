<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RespaldoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Respalda la base de datos y los archivos de la instalación. Lo corre el programador a diario
 * (routes/console.php) y se puede lanzar a mano antes de una actualización.
 */
class RespaldoCommand extends Command
{
    protected $signature = 'erp:respaldo';

    protected $description = 'Respalda la base de datos (pg_dump) y los archivos de la instalación';

    public function handle(RespaldoService $respaldos): int
    {
        try {
            $resultado = $respaldos->crear();
        } catch (Throwable $e) {
            Log::error('Respaldo fallido: '.$e->getMessage());
            $this->error('Respaldo fallido: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Base de datos: '.$resultado['base']);

        if ($resultado['archivos'] !== null) {
            $this->info('Archivos:      '.$resultado['archivos']);
        }

        if ($resultado['copia'] !== null) {
            $this->info('Copia en:      '.$resultado['copia']);
        }

        foreach ($resultado['avisos'] as $aviso) {
            $this->warn('Aviso: '.$aviso);
        }

        if ($resultado['eliminados'] > 0) {
            $this->line("Respaldos antiguos eliminados: {$resultado['eliminados']}");
        }

        return self::SUCCESS;
    }
}
