<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComboHeladoResource\Pages;

use App\Filament\Resources\ComboHeladoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCombosHelado extends ManageRecords
{
    protected static string $resource = ComboHeladoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
