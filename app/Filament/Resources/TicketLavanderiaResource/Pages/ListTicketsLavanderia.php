<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketLavanderiaResource\Pages;

use App\Filament\Resources\TicketLavanderiaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTicketsLavanderia extends ListRecords
{
    protected static string $resource = TicketLavanderiaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
