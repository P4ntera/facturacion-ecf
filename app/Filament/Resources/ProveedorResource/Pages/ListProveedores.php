<?php
<<<<<<< HEAD

namespace App\Filament\Resources\ProveedorResource\Pages;

use App\Filament\Resources\ProveedorResource;
use Filament\Actions\CreateAction;
=======
// ──────────────────────────────────────────────
//  Pages/ListProveedores.php
// ──────────────────────────────────────────────
namespace App\Filament\Resources\ProveedorResource\Pages;

use App\Filament\Resources\ProveedorResource;
use Filament\Actions;
>>>>>>> Lamar
use Filament\Resources\Pages\ListRecords;

class ListProveedores extends ListRecords
{
    protected static string $resource = ProveedorResource::class;

    protected function getHeaderActions(): array
    {
<<<<<<< HEAD
        return [CreateAction::make()];
=======
        return [
            Actions\CreateAction::make()
                ->label('Nuevo Proveedor'),
        ];
>>>>>>> Lamar
    }
}
