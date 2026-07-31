<?php

namespace App\Filament\Resources\DescuentoResource\Pages;

use App\Filament\Resources\DescuentoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDescuento extends EditRecord
{
    protected static string $resource = DescuentoResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
