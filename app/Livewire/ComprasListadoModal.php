<?php

namespace App\Livewire;

use App\Filament\Resources\CompraResource;
use App\Models\Compra;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Tabla completa de compras embebida en el modal de "Ver listado" de CreateCompra.
 * Usa una búsqueda propia (quickSearch) en vez del buscador estándar de Filament,
 * porque se alimenta con el atajo de "escribe donde sea para buscar" (ver Alpine
 * en la vista), no con un campo de búsqueda visible por defecto.
 */
class ComprasListadoModal extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public string $quickSearch = '';

    public function table(Table $table): Table
    {
        return CompraResource::table($table->query(Compra::query()))
            ->modifyQueryUsing(function (Builder $query) {
                if (filled($this->quickSearch)) {
                    $termino = $this->quickSearch;

                    $query->where(function (Builder $q) use ($termino) {
                        $q->where('ncf', 'ilike', "%{$termino}%")
                            ->orWhereRelation('proveedor', 'nombre', 'ilike', "%{$termino}%");
                    });
                }
            });
    }

    public function updatedQuickSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.compras-listado-modal');
    }
}
