<?php

declare(strict_types=1);

namespace App\Filament\Resources\PresentacionHeladoResource\Pages;

use App\Filament\Resources\PresentacionHeladoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePresentacionesHelado extends ManageRecords
{
    protected static string $resource = PresentacionHeladoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
