<?php

declare(strict_types=1);

namespace App\Filament\Resources\TicketLavanderiaResource\Pages;

use App\Filament\Resources\TicketLavanderiaResource;
use App\Services\TicketLavanderiaService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateTicketLavanderia extends CreateRecord
{
    protected static string $resource = TicketLavanderiaResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $empresa = Filament::getTenant();

        return app(TicketLavanderiaService::class)->crear(
            empresa: $empresa,
            recepcionista: auth()->user(),
            datos: $data,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
