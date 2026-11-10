<?php

declare(strict_types=1);

namespace App\Filament\Resources\SaborHeladoResource\Pages;

use App\Filament\Resources\SaborHeladoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSaboresHelado extends ManageRecords
{
    protected static string $resource = SaborHeladoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
