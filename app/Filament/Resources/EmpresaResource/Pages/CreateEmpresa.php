<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

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
    }
}
