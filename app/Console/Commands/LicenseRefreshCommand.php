<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmpresaLicenseState;
use App\Services\LicenseService;
use Illuminate\Console\Command;

/**
 * Recorre todas las empresas que tienen una license_key configurada y refresca su estado
 * llamando a la API de validación. Pensado para correr cada 6 horas vía el scheduler.
 *
 * Si LICENSE_ENABLED=false, no hace nada (sale limpio).
 */
class LicenseRefreshCommand extends Command
{
    protected $signature = 'license:refresh';

    protected $description = 'Revalida las licencias de todas las empresas con key configurada';

    public function handle(LicenseService $service): int
    {
        if (! $service->isEnabled()) {
            $this->info('Validación de licencia desactivada (LICENSE_ENABLED=false).');

            return self::SUCCESS;
        }

        $states = EmpresaLicenseState::query()
            ->whereNotNull('license_key')
            ->where('license_key', '!=', '')
            ->with('empresa')
            ->get();

        if ($states->isEmpty()) {
            $this->info('No hay empresas con license_key configurada.');

            return self::SUCCESS;
        }

        $this->info("Revalidando {$states->count()} licencia(s)...");

        $valid = 0;
        $invalid = 0;

        foreach ($states as $state) {
            $result = $service->refreshForEmpresa($state->empresa);

            $label = $state->empresa->razon_social;
            $status = $result['status'];
            $reason = $result['reason'] ? " ({$result['reason']})" : '';

            if ($result['valid']) {
                $this->line("  ✓ {$label}: {$status}");
                $valid++;
            } else {
                $this->warn("  ✗ {$label}: {$status}{$reason}");
                $invalid++;
            }
        }

        $this->info("Resultado: {$valid} válida(s), {$invalid} inválida(s).");

        return $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }
}
