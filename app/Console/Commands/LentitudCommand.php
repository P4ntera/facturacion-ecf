<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Resume el registro de lentitud (storage/logs/lento-*.log): qué consultas y qué pantallas se
 * repiten como lentas. Solo lee archivos; no toca la base de datos.
 */
class LentitudCommand extends Command
{
    protected $signature = 'erp:lentitud {--dias=7 : Cuántos días hacia atrás revisar}';

    protected $description = 'Resume las consultas y peticiones lentas registradas';

    public function handle(): int
    {
        $desde = now()->subDays(max(1, (int) $this->option('dias')))->startOfDay();
        $consultas = [];
        $origenes = [];
        $total = 0;

        foreach (glob(storage_path('logs/lento-*.log')) ?: [] as $archivo) {
            if (preg_match('/lento-(\d{4}-\d{2}-\d{2})\.log$/', $archivo, $m) !== 1 || $m[1] < $desde->toDateString()) {
                continue;
            }

            foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
                // Formato de Laravel: [fecha] entorno.WARNING: mensaje {json de contexto}
                if (preg_match('/WARNING: (consulta lenta|petición lenta) (\{.*\})\s*$/u', $linea, $partes) !== 1) {
                    continue;
                }

                $datos = json_decode($partes[2], true);

                if (! is_array($datos)) {
                    continue;
                }

                $total++;
                $ms = (int) ($datos['ms'] ?? 0);
                $origen = ($datos['componente'] ?? null) ?: ($datos['origen'] ?? 'desconocido');

                $origenes[$origen] ??= ['veces' => 0, 'peor' => 0];
                $origenes[$origen]['veces']++;
                $origenes[$origen]['peor'] = max($origenes[$origen]['peor'], $ms);

                if ($partes[1] === 'consulta lenta') {
                    $sql = (string) ($datos['sql'] ?? '');
                    $consultas[$sql] ??= ['veces' => 0, 'peor' => 0];
                    $consultas[$sql]['veces']++;
                    $consultas[$sql]['peor'] = max($consultas[$sql]['peor'], $ms);
                }
            }
        }

        if ($total === 0) {
            $this->info("Sin consultas ni peticiones lentas desde {$desde->toDateString()}.");

            return self::SUCCESS;
        }

        $this->line("{$total} registro(s) de lentitud desde {$desde->toDateString()}.");

        uasort($origenes, fn (array $a, array $b) => $b['veces'] <=> $a['veces']);
        uasort($consultas, fn (array $a, array $b) => $b['veces'] <=> $a['veces']);

        $this->newLine();
        $this->line('Pantallas / rutas más afectadas');
        $this->table(['Veces', 'Peor (ms)', 'Origen'], collect($origenes)->take(10)->map(
            fn (array $d, string $origen) => [$d['veces'], $d['peor'], $origen]
        )->values()->all());

        if ($consultas !== []) {
            $this->line('Consultas más repetidas');
            $this->table(['Veces', 'Peor (ms)', 'SQL'], collect($consultas)->take(10)->map(
                fn (array $d, string $sql) => [$d['veces'], $d['peor'], mb_strimwidth($sql, 0, 110, '…')]
            )->values()->all());
        }

        return self::SUCCESS;
    }
}
