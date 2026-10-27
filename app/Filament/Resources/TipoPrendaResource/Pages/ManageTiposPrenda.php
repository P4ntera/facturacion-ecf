<?php

declare(strict_types=1);

namespace App\Filament\Resources\TipoPrendaResource\Pages;

use App\Filament\Resources\TipoPrendaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTiposPrenda extends ManageRecords
{
    protected static string $resource = TipoPrendaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
