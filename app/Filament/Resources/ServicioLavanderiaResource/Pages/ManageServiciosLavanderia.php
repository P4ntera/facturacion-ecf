<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServicioLavanderiaResource\Pages;

use App\Filament\Resources\ServicioLavanderiaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageServiciosLavanderia extends ManageRecords
{
    protected static string $resource = ServicioLavanderiaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
