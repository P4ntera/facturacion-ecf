<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use App\Enums\PerfilEmpresa;
use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use Filament\Resources\Pages\CreateRecord;

class CreateEmpresa extends CreateRecord
{
    protected static string $resource = EmpresaResource::class;

    /** Toda empresa nueva entra ya con sus roles base y todos sus módulos habilitados. */
    protected function afterCreate(): void
    {
        /** @var Empresa $record */
        $record = $this->record;

        app(RolesEmpresaService::class)->sembrarRolesBase($record);
        app(ModulosEmpresaService::class)->sembrarModulos($record);

        // Lavandería siempre vende sin comprobante por defecto (el cliente lo pide si lo necesita).
        if ($record->perfil === PerfilEmpresa::LAVANDERIA) {
            $record->config()->update(['permite_ventas_sin_comprobante' => true]);
        }
    }
}
