<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComandaResource\Pages;

use App\Filament\Resources\ComandaResource;
use Filament\Resources\Pages\ListRecords;

class ListComandas extends ListRecords
{
    protected static string $resource = ComandaResource::class;
}
