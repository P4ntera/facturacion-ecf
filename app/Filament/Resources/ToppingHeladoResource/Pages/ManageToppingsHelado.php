<?php

declare(strict_types=1);

namespace App\Filament\Resources\ToppingHeladoResource\Pages;

use App\Filament\Resources\ToppingHeladoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageToppingsHelado extends ManageRecords
{
    protected static string $resource = ToppingHeladoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
